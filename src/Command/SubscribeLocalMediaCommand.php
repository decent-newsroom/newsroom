<?php

namespace App\Command;

use App\Repository\EventRepository;
use App\Service\MediaEventProjector;
use App\Service\Nostr\NostrRelayPool;
use App\Service\Nostr\RelayRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'media:subscribe-local-relay',
    description: 'Subscribe to local relay for new media events (kinds 20, 21, 22) and save them to the database in real-time'
)]
class SubscribeLocalMediaCommand extends Command
{
    public function __construct(
        private readonly NostrRelayPool $relayPool,
        private readonly RelayRegistry $relayRegistry,
        private readonly MediaEventProjector $projector,
        private readonly EventRepository $eventRepository,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            'This command subscribes to the local Nostr relay for media events (kinds 20, 21, 22) ' .
            'and automatically persists them to the database. It runs as a long-lived daemon process.' . "\n\n" .
            'Supported event kinds:' . "\n" .
            '  - Kind 20: Pictures (NIP-68)' . "\n" .
            '  - Kind 21: Videos horizontal (NIP-68)' . "\n" .
            '  - Kind 22: Videos vertical (NIP-68)'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $localRelay = $this->relayRegistry->getLocalRelay();
        if (!$localRelay) {
            $io->error('Local relay not configured. Please set NOSTR_DEFAULT_RELAY environment variable.');
            return Command::FAILURE;
        }

        $io->title('Media Hydration Worker');
        $io->info(sprintf('Subscribing to local relay: %s', $localRelay));
        $io->info('Listening for media events (kinds 20, 21, 22)...');
        $io->newLine();

        // Display stats before starting
        try {
            $stats = $this->projector->getMediaStats();
            $io->section('Current Database Stats');
            $io->writeln(sprintf('  Total media events: <fg=cyan>%d</>', $stats['total']));
            foreach ($stats['by_kind'] as $kind => $count) {
                $kindName = match($kind) {
                    20 => 'Pictures',
                    21 => 'Videos (horizontal)',
                    22 => 'Videos (vertical)',
                    default => "Kind $kind"
                };
                $io->writeln(sprintf('  - %s: <fg=cyan>%d</>', $kindName, $count));
            }
            $io->newLine();
        } catch (\Exception $e) {
            $io->warning('Could not retrieve media stats: ' . $e->getMessage());
        }

        try {
            // Determine the "since" timestamp so the relay skips events we already have
            $since = $this->eventRepository->findLatestCreatedAtByKinds([20, 21, 22, 34235, 34236]);
            if ($since !== null) {
                $io->info(sprintf('Resuming from last known event: %s (timestamp %d)', date('Y-m-d H:i:s', $since), $since));
            } else {
                $io->info('No existing media events — fetching full history from relay');
            }

            // Start the long-lived subscription
            // This blocks forever and processes events via the callback
            $this->relayPool->subscribeLocalMedia(
                function (object $event, string $relayUrl) use ($io) {
                    $timestamp = date('Y-m-d H:i:s');
                    $eventId = substr($event->id ?? 'unknown', 0, 16) . '...';
                    $pubkey = substr($event->pubkey ?? 'unknown', 0, 16) . '...';
                    $kind = $event->kind ?? 'unknown';

                    $kindName = match($kind) {
                        20 => 'Picture',
                        21 => 'Video (H)',
                        22 => 'Video (V)',
                        default => "Kind $kind"
                    };

                    // Check for media URL in tags
                    $hasUrl = false;
                    if (isset($event->tags) && is_array($event->tags)) {
                        foreach ($event->tags as $tag) {
                            if (is_array($tag) && isset($tag[0]) && in_array($tag[0], ['url', 'image'])) {
                                $hasUrl = true;
                                break;
                            }
                        }
                    }

                    // Log to console
                    $io->writeln(sprintf(
                        '[%s] <fg=green>Event received:</> %s (%s, pubkey: %s)%s',
                        $timestamp,
                        $eventId,
                        $kindName,
                        $pubkey,
                        $hasUrl ? ' 📷' : ''
                    ));

                    // Project the event to the database
                    try {
                        $this->projector->projectMediaFromEvent($event, $relayUrl);
                        $io->writeln(sprintf(
                            '[%s] <fg=green>✓</> Media event saved to database',
                            date('Y-m-d H:i:s')
                        ));
                    } catch (\InvalidArgumentException $e) {
                        // Invalid event (wrong kind, bad signature, etc.)
                        $io->writeln(sprintf(
                            '[%s] <fg=yellow>⚠</> Skipped invalid event: %s',
                            date('Y-m-d H:i:s'),
                            $e->getMessage()
                        ));
                    } catch (\Exception $e) {
                        // Database or other errors
                        $io->writeln(sprintf(
                            '[%s] <fg=red>✗</> Error saving media event: %s',
                            date('Y-m-d H:i:s'),
                            $e->getMessage()
                        ));
                    }

                    $io->newLine();
                },
                $since
            );

            // @phpstan-ignore-next-line - This line should never be reached (infinite loop in subscribeLocalMedia)
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Subscription failed: ' . $e->getMessage());
            $this->logger->error('Media hydration worker failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }
}
