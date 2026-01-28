<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'curation:backfill',
    description: 'Backfill curation set events (kind 30004, 30005, 30006) from Nostr relays',
)]
class BackfillCurationSetsCommand extends Command
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly EventRepository $eventRepository,
        private readonly GenericEventProjector $projector,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', 'd', InputOption::VALUE_OPTIONAL, 'Number of days to backfill (default: 30)', 30)
            ->addOption('kinds', 'k', InputOption::VALUE_OPTIONAL, 'Comma-separated list of kinds to fetch (default: 30004,30005,30006)', '30004,30005,30006')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Maximum number of events to fetch per request (default: 500)', 500)
            ->addOption('pubkey', 'p', InputOption::VALUE_OPTIONAL, 'Filter by specific pubkey (hex format)', null)
            ->setHelp(
                'This command backfills curation set events from Nostr relays into the local database.' . "\n\n" .
                'Examples:' . "\n" .
                '  # Backfill last 30 days of all curation set types (default):' . "\n" .
                '  php bin/console curation:backfill' . "\n\n" .
                '  # Backfill last 90 days:' . "\n" .
                '  php bin/console curation:backfill --days=90' . "\n\n" .
                '  # Backfill only kind 30004 (articles/notes curation) from last 14 days:' . "\n" .
                '  php bin/console curation:backfill --days=14 --kinds=30004' . "\n\n" .
                '  # Backfill curation sets for a specific user:' . "\n" .
                '  php bin/console curation:backfill --pubkey=abc123...' . "\n\n" .
                'Supported event kinds (NIP-51):' . "\n" .
                '  - Kind 30004: Curation Sets (Articles/Notes)' . "\n" .
                '  - Kind 30005: Curation Sets (Videos)' . "\n" .
                '  - Kind 30006: Curation Sets (Pictures)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Parse options
        $days = (int) $input->getOption('days');
        $kindsStr = (string) $input->getOption('kinds');
        $limit = (int) $input->getOption('limit');
        $pubkey = $input->getOption('pubkey');

        $kinds = array_map('intval', array_filter(explode(',', $kindsStr)));

        // Validate kinds
        $validKinds = [
            KindsEnum::CURATION_SET->value,      // 30004
            KindsEnum::CURATION_VIDEOS->value,   // 30005
            KindsEnum::CURATION_PICTURES->value  // 30006
        ];

        foreach ($kinds as $kind) {
            if (!in_array($kind, $validKinds)) {
                $io->error(sprintf('Invalid kind %d. Supported kinds: %s', $kind, implode(', ', $validKinds)));
                return Command::FAILURE;
            }
        }

        if (empty($kinds)) {
            $io->error('No valid kinds specified. Use comma-separated integers like: 30004,30005,30006');
            return Command::FAILURE;
        }

        // Calculate time range
        $to = time();
        $from = $to - ($days * 24 * 60 * 60);

        $io->title('Curation Sets Backfill');
        $io->info('Fetching curation set events from Nostr relays');
        $io->writeln(sprintf('  Time range: %s to %s', date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $to)));
        $io->writeln(sprintf('  Event kinds: %s', $this->formatKinds($kinds)));
        $io->writeln(sprintf('  Limit per request: %d', $limit));
        if ($pubkey) {
            $io->writeln(sprintf('  Pubkey filter: %s...', substr($pubkey, 0, 16)));
        }
        $io->newLine();

        try {
            // Fetch curation events from relays
            $io->section('Fetching from relays...');
            $startTime = microtime(true);

            $rawEvents = $this->fetchCurationEventsFromRelays($kinds, $from, $to, $limit, $pubkey);

            $fetchDuration = round(microtime(true) - $startTime, 2);
            $io->success(sprintf('Fetched %d events in %s seconds', count($rawEvents), $fetchDuration));

            if (empty($rawEvents)) {
                $io->warning('No curation set events found in the specified time range.');
                return Command::SUCCESS;
            }

            // Save events to database
            $io->section('Saving to database...');
            $startTime = microtime(true);

            $savedCount = 0;
            $skippedCount = 0;
            $errorCount = 0;

            $progressBar = $io->createProgressBar(count($rawEvents));
            $progressBar->start();

            foreach ($rawEvents as $rawEvent) {
                try {
                    $this->projector->projectEventFromNostrEvent($rawEvent, 'backfill');
                    $savedCount++;
                } catch (\InvalidArgumentException $e) {
                    // Event already exists or invalid
                    $skippedCount++;
                    $this->logger->debug('Skipped curation event', [
                        'event_id' => $rawEvent->id ?? 'unknown',
                        'reason' => $e->getMessage()
                    ]);
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->logger->warning('Failed to save curation event', [
                        'event_id' => $rawEvent->id ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
                $progressBar->advance();
            }

            $progressBar->finish();
            $io->newLine(2);

            $saveDuration = round(microtime(true) - $startTime, 2);

            // Display results
            $io->section('Results');
            $io->writeln(sprintf('  <fg=green>✓</> Saved: %d', $savedCount));
            $io->writeln(sprintf('  <fg=yellow>⊘</> Skipped (already exist): %d', $skippedCount));
            if ($errorCount > 0) {
                $io->writeln(sprintf('  <fg=red>✗</> Errors: %d', $errorCount));
            }
            $io->writeln(sprintf('  Duration: %s seconds', $saveDuration));
            $io->newLine();

            // Show stats by kind
            $io->section('Events by Kind');
            $kindCounts = $this->countEventsByKind($rawEvents);
            foreach ($kindCounts as $kind => $count) {
                $kindLabel = $this->getKindLabel($kind);
                $io->writeln(sprintf('  %s (kind %d): %d', $kindLabel, $kind, $count));
            }
            $io->newLine();

            if ($savedCount > 0) {
                $io->success(sprintf('Successfully backfilled %d new curation set events!', $savedCount));
            } else {
                $io->info('No new curation set events to save (all already exist in database).');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Backfill failed: ' . $e->getMessage());
            $this->logger->error('Curation sets backfill failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Fetch curation set events from Nostr relays
     */
    private function fetchCurationEventsFromRelays(array $kinds, int $from, int $to, int $limit, ?string $pubkey = null): array
    {
        $this->logger->info('Fetching curation set events', [
            'kinds' => $kinds,
            'from' => date('Y-m-d H:i:s', $from),
            'to' => date('Y-m-d H:i:s', $to),
            'pubkey' => $pubkey ? substr($pubkey, 0, 16) . '...' : null,
        ]);

        // Use NostrClient's getCurationEventsByTimeRange method
        return $this->nostrClient->getCurationEventsByTimeRange($kinds, $from, $to, $limit, $pubkey);
    }

    /**
     * Format kinds for display
     */
    private function formatKinds(array $kinds): string
    {
        $labels = [];
        foreach ($kinds as $kind) {
            $labels[] = sprintf('%d (%s)', $kind, $this->getKindLabel($kind));
        }
        return implode(', ', $labels);
    }

    /**
     * Get human-readable label for a kind
     */
    private function getKindLabel(int $kind): string
    {
        return match($kind) {
            30004 => 'Articles/Notes',
            30005 => 'Videos',
            30006 => 'Pictures',
            default => 'Unknown'
        };
    }

    /**
     * Count events by kind
     */
    private function countEventsByKind(array $events): array
    {
        $counts = [];
        foreach ($events as $event) {
            $kind = $event->kind ?? 0;
            if (!isset($counts[$kind])) {
                $counts[$kind] = 0;
            }
            $counts[$kind]++;
        }
        ksort($counts);
        return $counts;
    }
}
