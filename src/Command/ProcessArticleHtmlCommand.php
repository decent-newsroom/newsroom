<?php

namespace App\Command;

use App\Util\CommonMark\Converter;
use Doctrine\ORM\EntityManagerInterface;
use nostriphant\NIP19\Bech32;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'articles:process-html',
    description: 'Process and cache HTML for articles that are missing processed HTML content'
)]
class ProcessArticleHtmlCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Converter $converter
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force reprocessing of all articles (including those with existing HTML)')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit the number of articles to process', null)
            ->addOption('delete-failed', null, InputOption::VALUE_NONE, 'Delete articles that fail HTML processing instead of skipping them')
            ->addOption('timeout', 't', InputOption::VALUE_REQUIRED, 'Maximum seconds per article before skipping (default: 60)', 60)
            ->addOption('math-only', null, InputOption::VALUE_NONE, 'Only reprocess articles whose content contains a $ sign (useful after math-delimiter changes)')
            ->addOption('naddr', null, InputOption::VALUE_REQUIRED, 'Process only the article identified by the given naddr1… bech32 string')
            ->setHelp(
                'This command processes content to HTML for articles and caches the result in the database. ' .
                'By default, it only processes articles that are missing processed HTML, newest first. ' .
                'Use --force to reprocess all articles. ' .
                'Use --math-only to reprocess only articles that may contain math (implies --force for those articles). ' .
                'Use --delete-failed to remove articles that cannot be processed (e.g. invalid npub data). ' .
                'Use --timeout to set the maximum seconds per article (default 60). Articles that exceed this are skipped.' . "\n\n" .
                'The converter never makes relay calls. Nostr references not found in the local DB ' .
                'are stored as deferred embeds and resolved at template render time.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $limit = $input->getOption('limit');
        $deleteFailed = (bool) $input->getOption('delete-failed');
        $timeout = max(5, (int) $input->getOption('timeout'));
        $mathOnly = (bool) $input->getOption('math-only');
        $naddr = $input->getOption('naddr');

        $io->title('Article HTML Processing');

        // If --naddr is provided, resolve it to a single article and process only that one.
        if ($naddr !== null) {
            return $this->processNaddr($io, $naddr, $deleteFailed, $timeout);
        }

        if ($deleteFailed) {
            $io->caution('--delete-failed is active: articles that fail processing will be permanently deleted.');
        }

        // Set up per-article timeout via pcntl_alarm (Linux/Docker).
        $hasPcntl = \function_exists('pcntl_alarm') && \function_exists('pcntl_signal');
        if ($hasPcntl) {
            pcntl_signal(SIGALRM, static function () {
                throw new \RuntimeException('Article processing timed out');
            });
            $io->info(sprintf('Per-article timeout: %ds (signal-based)', $timeout));
        } else {
            $io->info(sprintf('Per-article timeout: %ds (wall-clock)', $timeout));
        }

        $conn = $this->entityManager->getConnection();

        // Fetch IDs only to avoid hydrating full entities (raw JSON fields can be huge).
        $sql = 'SELECT id FROM article WHERE content IS NOT NULL';
        if ($mathOnly) {
            $sql .= " AND content LIKE '%\$%'";
        } elseif (!$force) {
            $sql .= ' AND processed_html IS NULL';
        }
        $sql .= ' ORDER BY id DESC';
        if ($limit) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $articleIds = $conn->fetchFirstColumn($sql);
        $total = count($articleIds);

        if ($total === 0) {
            $io->success('No articles to process.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d article(s) to process', $total));
        $io->newLine();

        $progressBar = $io->createProgressBar($total);
        $progressBar->setFormat('very_verbose');
        $progressBar->start();

        $processed = 0;
        $failed = 0;
        $skipped = 0;
        $deleted = 0;
        $deletedIds = [];
        $batchSize = 50;
        $inBatch = 0;

        foreach ($articleIds as $articleId) {
            if ($hasPcntl) {
                pcntl_alarm($timeout);
            }
            $deadline = microtime(true) + $timeout;

            try {
                $row = $conn->fetchAssociative('SELECT content, raw FROM article WHERE id = ?', [$articleId]);
                if (!$row || !is_string($row['content']) || $row['content'] === '') {
                    $progressBar->advance();
                    continue;
                }

                $content = $row['content'];

                $tags = null;
                if (!empty($row['raw'])) {
                    $rawData = is_string($row['raw']) ? json_decode($row['raw'], true) : $row['raw'];
                    if (is_array($rawData) && isset($rawData['tags']) && is_array($rawData['tags'])) {
                        $tags = $rawData['tags'];
                    }
                }

                $html = $this->converter->convertToHTML($content, null, $tags);

                if (!$hasPcntl && microtime(true) > $deadline) {
                    throw new \RuntimeException('Article processing timed out');
                }

                $conn->executeStatement(
                    'UPDATE article SET processed_html = :html WHERE id = :id',
                    ['html' => $html, 'id' => $articleId]
                );

                $processed++;
                $inBatch++;

                if ($inBatch >= $batchSize) {
                    $this->entityManager->clear();
                    $inBatch = 0;
                }
            } catch (\Throwable $e) {
                $isTimeout = str_contains($e->getMessage(), 'timed out');

                if ($isTimeout) {
                    $skipped++;
                    $io->writeln('');
                    $io->warning(sprintf('Skipped article ID %s: exceeded %ds timeout', $articleId, $timeout));
                } elseif ($deleteFailed) {
                    $failed++;
                    try {
                        $conn->executeStatement('DELETE FROM article WHERE id = ?', [$articleId]);
                        $deleted++;
                        $deletedIds[] = $articleId;
                        $io->writeln('');
                        $io->warning(sprintf('Deleted article ID %s: %s', $articleId, $e->getMessage()));
                    } catch (\Throwable $deleteEx) {
                        $io->writeln('');
                        $io->error(sprintf('Failed to delete article ID %s: %s', $articleId, $deleteEx->getMessage()));
                    }
                } else {
                    $failed++;
                    $io->writeln('');
                    $io->warning(sprintf('Failed to process article ID %s: %s', $articleId, $e->getMessage()));
                }
            }

            if ($hasPcntl) {
                pcntl_alarm(0);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);

        $summary = sprintf('Processing complete: %d processed, %d failed', $processed, $failed);
        if ($skipped > 0) {
            $summary .= sprintf(', %d skipped (timeout)', $skipped);
        }
        $io->success($summary);

        if ($deleted > 0) {
            $io->warning(sprintf('%d article(s) were deleted: IDs %s', $deleted, implode(', ', $deletedIds)));
        }

        if ($failed > 0 && !$deleteFailed) {
            $io->note('Some articles failed to process. Check the warnings above for details, or re-run with --delete-failed to remove them.');
        }

        if ($skipped > 0) {
            $io->note(sprintf('Some articles were skipped due to timeout. Try increasing --timeout (current: %ds) or check those articles for excessive nostr references.', $timeout));
        }

        return Command::SUCCESS;
    }

    private function processNaddr(SymfonyStyle $io, string $naddr, bool $deleteFailed, int $timeout): int
    {
        try {
            $decoded = new Bech32($naddr);
        } catch (\Throwable $e) {
            $io->error('Failed to decode naddr: ' . $e->getMessage());
            return Command::FAILURE;
        }

        if ($decoded->type !== 'naddr') {
            $io->error(sprintf('Expected an naddr identifier, got "%s".', $decoded->type));
            return Command::FAILURE;
        }

        $data = $decoded->data;
        $pubkey = $data->pubkey;
        $identifier = $data->identifier;

        $io->info(sprintf('Looking up article: pubkey=%s, slug=%s', $pubkey, $identifier));

        $conn = $this->entityManager->getConnection();
        $row = $conn->fetchAssociative(
            'SELECT id, content, raw FROM article WHERE pubkey = :pubkey AND slug = :slug',
            ['pubkey' => $pubkey, 'slug' => $identifier]
        );

        if (!$row) {
            $io->error('No article found for this naddr.');
            return Command::FAILURE;
        }

        if (!is_string($row['content']) || $row['content'] === '') {
            $io->warning('Article has no content to process.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Processing article ID %s', $row['id']));

        try {
            $tags = null;
            if (!empty($row['raw'])) {
                $rawData = is_string($row['raw']) ? json_decode($row['raw'], true) : $row['raw'];
                if (is_array($rawData) && isset($rawData['tags']) && is_array($rawData['tags'])) {
                    $tags = $rawData['tags'];
                }
            }

            $html = $this->converter->convertToHTML($row['content'], null, $tags);

            $conn->executeStatement(
                'UPDATE article SET processed_html = :html WHERE id = :id',
                ['html' => $html, 'id' => $row['id']]
            );

            $io->success(sprintf('Article ID %s processed successfully.', $row['id']));
        } catch (\Throwable $e) {
            $io->error(sprintf('Failed to process article ID %s: %s', $row['id'], $e->getMessage()));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}

