<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Service\CommentEventProjector;
use App\Service\Nostr\NostrClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'comments:backfill',
    description: 'Backfill comments and zaps from relays into the database',
)]
class BackfillCommentsCommand extends Command
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly ArticleRepository $articleRepository,
        private readonly EventRepository $eventRepository,
        private readonly CommentEventProjector $commentProjector,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('coordinate', 'c', InputOption::VALUE_OPTIONAL, 'Backfill comments for a specific article coordinate')
            ->addOption('days', 'd', InputOption::VALUE_OPTIONAL, 'Number of days to backfill (default: 30)', 30)
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Maximum number of comments to fetch per request (default: 1000)', 1000)
            ->setHelp(
                'This command backfills comments and zaps from the local relay into the database.' . "\n\n" .
                'Examples:' . "\n" .
                '  # Backfill all comments from last 30 days:' . "\n" .
                '  php bin/console comments:backfill' . "\n\n" .
                '  # Backfill comments for specific article:' . "\n" .
                '  php bin/console comments:backfill --coordinate=30023:pubkey:slug' . "\n\n" .
                '  # Backfill last 7 days:' . "\n" .
                '  php bin/console comments:backfill --days=7'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Parse options
        $coordinate = $input->getOption('coordinate');
        $days = (int) $input->getOption('days');
        $limit = (int) $input->getOption('limit');

        $io->title('Comments and Zaps Backfill');

        if ($coordinate) {
            return $this->backfillForArticle($io, $coordinate);
        }

        return $this->backfillAll($io, $days, $limit);
    }

    private function backfillForArticle(SymfonyStyle $io, string $coordinate): int
    {
        $io->info(sprintf('Fetching comments for article: %s', $coordinate));

        try {
            // Fetch comments from relay
            $comments = $this->nostrClient->getComments($coordinate);

            if (empty($comments)) {
                $io->warning('No comments found for this article.');
                return Command::SUCCESS;
            }

            $io->writeln(sprintf('Found %d comment/zap events', count($comments)));

            // Persist to database
            $io->section('Saving to database...');
            $persistedCount = $this->commentProjector->projectEvents($comments);

            $io->success(sprintf('Backfilled %d new comments/zaps', $persistedCount));

            // Show current count
            $totalCount = $this->eventRepository->countCommentsByCoordinate($coordinate);
            $io->info(sprintf('Total comments in database for this article: %d', $totalCount));

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Failed to backfill comments: ' . $e->getMessage());
            $this->logger->error('Comment backfill failed', [
                'coordinate' => $coordinate,
                'error' => $e->getMessage()
            ]);
            return Command::FAILURE;
        }
    }

    private function backfillAll(SymfonyStyle $io, int $days, int $limit): int
    {
        $io->info(sprintf('Starting full backfill for articles from the last %d days...', $days));

        // Calculate the "since" timestamp
        $since = new \DateTimeImmutable(sprintf('-%d days', $days));

        // Fetch all articles that might have comments
        $io->section('Fetching articles from database...');

        $qb = $this->articleRepository->createQueryBuilder('a');
        $qb->where('a.publishedAt >= :since')
            ->andWhere('a.publishedAt IS NOT NULL')
            ->andWhere('a.slug IS NOT NULL')
            ->setParameter('since', $since)
            ->orderBy('a.publishedAt', 'DESC');

        $articles = $qb->getQuery()->getResult();

        if (empty($articles)) {
            $io->warning('No articles found in the specified time range.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf('Found %d articles to backfill', count($articles)));
        $io->newLine();

        $progressBar = $io->createProgressBar(count($articles));
        $progressBar->setFormat('very_verbose');

        $totalComments = 0;
        $articlesWithComments = 0;
        $failedArticles = 0;

        foreach ($articles as $article) {
            try {
                // Build coordinate from article
                $coordinate = $this->buildCoordinate($article);

                if (!$coordinate) {
                    $this->logger->warning('Could not build coordinate from article', [
                        'article_id' => $article->getId(),
                        'slug' => $article->getSlug()
                    ]);
                    $failedArticles++;
                    $progressBar->advance();
                    continue;
                }

                // Fetch comments from relays
                $comments = $this->nostrClient->getComments($coordinate);

                if (!empty($comments)) {
                    // Persist to database
                    $persistedCount = $this->commentProjector->projectEvents($comments);

                    if ($persistedCount > 0) {
                        $totalComments += $persistedCount;
                        $articlesWithComments++;
                    }
                }

                $progressBar->advance();

            } catch (\Exception $e) {
                $this->logger->error('Failed to backfill comments for article', [
                    'article_id' => $article->getId(),
                    'slug' => $article->getSlug(),
                    'error' => $e->getMessage()
                ]);
                $failedArticles++;
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        // Summary
        $io->success('Backfill complete!');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Total articles processed', count($articles)],
                ['Articles with comments', $articlesWithComments],
                ['Total comments/zaps backfilled', $totalComments],
                ['Failed articles', $failedArticles],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * Build coordinate from Article entity
     * Returns coordinate in format: kind:pubkey:identifier
     */
    private function buildCoordinate(\App\Entity\Article $article): ?string
    {
        $slug = $article->getSlug();
        $pubkey = $article->getPubkey();
        $kind = $article->getKind();

        if (!$slug || !$pubkey || !$kind) {
            return null;
        }

        return sprintf('%d:%s:%s', $kind->value, $pubkey, $slug);
    }
}
