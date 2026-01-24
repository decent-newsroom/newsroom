<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\EventRepository;
use App\Service\Nostr\NostrClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'media:backfill',
    description: 'Backfill media events (kind 20, 21, 22) from Nostr relays',
)]
class BackfillMediaEventsCommand extends Command
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly EventRepository $eventRepository,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', 'd', InputOption::VALUE_OPTIONAL, 'Number of days to backfill (default: 7)', 7)
            ->addOption('kinds', 'k', InputOption::VALUE_OPTIONAL, 'Comma-separated list of kinds to fetch (default: 20,21,22)', '20,21,22')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Maximum number of events to fetch per request (default: 1000)', 1000)
            ->setHelp(
                'This command backfills media events from Nostr relays into the local database.' . "\n\n" .
                'Examples:' . "\n" .
                '  # Backfill last 7 days of all media types (default):' . "\n" .
                '  php bin/console media:backfill' . "\n\n" .
                '  # Backfill last 30 days:' . "\n" .
                '  php bin/console media:backfill --days=30' . "\n\n" .
                '  # Backfill only kind 20 (pictures) from last 14 days:' . "\n" .
                '  php bin/console media:backfill --days=14 --kinds=20' . "\n\n" .
                'Supported event kinds:' . "\n" .
                '  - Kind 20: Pictures (NIP-68)' . "\n" .
                '  - Kind 21: Videos horizontal (NIP-68)' . "\n" .
                '  - Kind 22: Videos vertical (NIP-68)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Parse options
        $days = (int) $input->getOption('days');
        $kindsStr = (string) $input->getOption('kinds');
        $limit = (int) $input->getOption('limit');

        $kinds = array_map('intval', array_filter(explode(',', $kindsStr)));

        if (empty($kinds)) {
            $io->error('Invalid kinds specified. Use comma-separated integers like: 20,21,22');
            return Command::FAILURE;
        }

        // Calculate time range
        $to = time();
        $from = $to - ($days * 24 * 60 * 60);

        $io->title('Media Events Backfill');
        $io->info(sprintf('Fetching media events from Nostr relays'));
        $io->writeln(sprintf('  Time range: %s to %s', date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $to)));
        $io->writeln(sprintf('  Event kinds: %s', implode(', ', $kinds)));
        $io->writeln(sprintf('  Limit per request: %d', $limit));
        $io->newLine();

        try {
            // Fetch media events from relays
            $io->section('Fetching from relays...');
            $startTime = microtime(true);

            $rawEvents = $this->fetchMediaEventsFromRelays($kinds, $from, $to, $limit);

            $fetchDuration = round(microtime(true) - $startTime, 2);
            $io->success(sprintf('Fetched %d events in %s seconds', count($rawEvents), $fetchDuration));

            if (empty($rawEvents)) {
                $io->warning('No media events found in the specified time range.');
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
                    $this->nostrClient->saveMediaEvent($rawEvent);
                    $savedCount++;
                } catch (\InvalidArgumentException $e) {
                    // Event already exists or invalid
                    $skippedCount++;
                    $this->logger->debug('Skipped media event', [
                        'event_id' => $rawEvent->id ?? 'unknown',
                        'reason' => $e->getMessage()
                    ]);
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->logger->warning('Failed to save media event', [
                        'event_id' => $rawEvent->id ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                }
                $progressBar->advance();
            }

            // Flush all changes
            $this->eventRepository->flush();

            $progressBar->finish();
            $io->newLine(2);

            $saveDuration = round(microtime(true) - $startTime, 2);

            // Display results
            $io->section('Results');
            $io->writeln(sprintf('  <fg=green>✓</> Saved: %d', $savedCount));
            $io->writeln(sprintf('  <fg=yellow>⊘</> Skipped: %d', $skippedCount));
            if ($errorCount > 0) {
                $io->writeln(sprintf('  <fg=red>✗</> Errors: %d', $errorCount));
            }
            $io->writeln(sprintf('  Duration: %s seconds', $saveDuration));
            $io->newLine();

            if ($savedCount > 0) {
                $io->success(sprintf('Successfully backfilled %d new media events!', $savedCount));
            } else {
                $io->info('No new media events to save (all already exist in database).');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Backfill failed: ' . $e->getMessage());
            $this->logger->error('Media backfill failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * Fetch media events from Nostr relays
     */
    private function fetchMediaEventsFromRelays(array $kinds, int $from, int $to, int $limit): array
    {
        $allEvents = [];

        // Use the existing NostrClient method to fetch media events
        // We'll need to call the relay pool directly with a time-based filter

        // For now, use a simple approach: fetch recent media events
        // You can extend this to query multiple relays or use different strategies

        foreach ($kinds as $kind) {
            $kindName = match($kind) {
                20 => 'Pictures',
                21 => 'Videos (horizontal)',
                22 => 'Videos (vertical)',
                default => "Kind $kind"
            };

            $this->logger->info('Fetching media events', [
                'kind' => $kind,
                'kind_name' => $kindName,
                'from' => date('Y-m-d H:i:s', $from),
                'to' => date('Y-m-d H:i:s', $to),
            ]);

            // Use NostrClient's existing infrastructure
            // We need to create a custom request for this
            $events = $this->nostrClient->getMediaEventsByTimeRange($kinds, $from, $to, $limit);
            $allEvents = array_merge($allEvents, $events);
        }

        return $allEvents;
    }
}
