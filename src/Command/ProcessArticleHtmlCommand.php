<?php

namespace App\Command;

use App\Entity\Article;
use App\Util\CommonMark\Converter;
use Doctrine\ORM\EntityManagerInterface;
use League\CommonMark\Exception\CommonMarkException;
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
            ->setHelp(
                'This command processes content to HTML for articles and caches the result in the database. ' .
                'By default, it only processes articles that are missing processed HTML. ' .
                'Use --force to reprocess all articles. ' .
                'Use --delete-failed to remove articles that cannot be processed (e.g. invalid npub data).'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');
        $limit = $input->getOption('limit');
        $deleteFailed = (bool) $input->getOption('delete-failed');

        $io->title('Article HTML Processing');

        if ($deleteFailed) {
            $io->caution('--delete-failed is active: articles that fail processing will be permanently deleted.');
        }

        $conn = $this->entityManager->getConnection();

        // Fetch IDs only to avoid hydrating full entities (raw JSON fields can be huge).
        $sql = 'SELECT id FROM article WHERE content IS NOT NULL';
        if (!$force) {
            $sql .= ' AND processed_html IS NULL';
        }
        $sql .= ' ORDER BY id ASC';
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
        $deleted = 0;
        $deletedIds = [];
        $batchSize = 50;
        $inBatch = 0;

        foreach ($articleIds as $articleId) {
            try {
                // Fetch content and raw JSON for tag-based prefetching.
                $row = $conn->fetchAssociative('SELECT content, raw FROM article WHERE id = ?', [$articleId]);
                if (!$row || !is_string($row['content']) || $row['content'] === '') {
                    $progressBar->advance();
                    continue;
                }

                $content = $row['content'];

                // Extract tags from raw event JSON (p, e, a tags seed the bulk prefetch)
                $tags = null;
                if (!empty($row['raw'])) {
                    $rawData = is_string($row['raw']) ? json_decode($row['raw'], true) : $row['raw'];
                    if (is_array($rawData) && isset($rawData['tags']) && is_array($rawData['tags'])) {
                        $tags = $rawData['tags'];
                    }
                }

                $html = $this->converter->convertToHTML($content, null, $tags);

                $conn->executeStatement(
                    'UPDATE article SET processed_html = :html WHERE id = :id',
                    ['html' => $html, 'id' => $articleId]
                );

                $processed++;
                $inBatch++;

                // Keep memory stable over long runs.
                if ($inBatch >= $batchSize) {
                    $this->entityManager->clear();
                    $inBatch = 0;
                }
            } catch (\Throwable $e) {
                $failed++;

                if ($deleteFailed) {
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
                    $io->writeln('');
                    $io->warning(sprintf('Failed to process article ID %s: %s', $articleId, $e->getMessage()));
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $io->newLine(2);

        $io->success(sprintf('Processing complete: %d processed, %d failed', $processed, $failed));

        if ($deleted > 0) {
            $io->warning(sprintf('%d article(s) were deleted: IDs %s', $deleted, implode(', ', $deletedIds)));
        }

        if ($failed > 0 && !$deleteFailed) {
            $io->note('Some articles failed to process. Check the warnings above for details, or re-run with --delete-failed to remove them.');
        }

        return Command::SUCCESS;
    }
}

