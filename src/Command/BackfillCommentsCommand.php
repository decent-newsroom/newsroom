<?php

declare(strict_types=1);

namespace App\Command;

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
    description: 'Backfill comments and zaps from local relay into the database',
)]
class BackfillCommentsCommand extends Command
{
    public function __construct(
        private readonly NostrClient $nostrClient,
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
        $io->warning('Full backfill not yet implemented.');
        $io->note('To backfill comments for a specific article, use: --coordinate=30023:pubkey:slug');

        // TODO: Implement full backfill by:
        // 1. Fetching all articles from the database
        // 2. For each article, fetch comments from relay
        // 3. Persist to database
        // This could be resource-intensive and should probably be done in batches

        return Command::SUCCESS;
    }
}
