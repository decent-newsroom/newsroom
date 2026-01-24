<?php

namespace App\Command;

use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrRelayPool;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'magazines:subscribe-local-relay',
    description: 'Subscribe to local relay for magazine events (kind 30040) and save them to the database in real-time'
)]
class SubscribeLocalMagazinesCommand extends Command
{
    public function __construct(
        private readonly NostrRelayPool $relayPool,
        private readonly GenericEventProjector $projector,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'kinds',
                null,
                InputOption::VALUE_OPTIONAL,
                'Comma-separated list of event kinds to subscribe to',
                '30040'
            )
            ->setHelp(
                'This command subscribes to the local Nostr relay for magazine/reading list events (kind 30040) ' .
                'and automatically persists them to the database as generic events. ' .
                'It runs as a long-lived daemon process.' . "\n\n" .
                'Supported event kinds:' . "\n" .
                '  - Kind 30040: Magazine indices / Reading lists (NIP-51)' . "\n\n" .
                'You can specify additional kinds using --kinds=30040,30041'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $localRelay = $this->relayPool->getLocalRelay();
        if (!$localRelay) {
            $io->error('Local relay not configured. Please set NOSTR_DEFAULT_RELAY environment variable.');
            return Command::FAILURE;
        }

        // Parse kinds from option
        $kindsString = $input->getOption('kinds');
        $kinds = array_map('intval', array_filter(explode(',', $kindsString)));

        if (empty($kinds)) {
            $io->error('No valid event kinds specified. Please provide at least one kind using --kinds.');
            return Command::FAILURE;
        }

        $io->title('Magazine/Generic Event Hydration Worker');
        $io->info(sprintf('Subscribing to local relay: %s', $localRelay));
        $io->info(sprintf('Listening for event kinds: %s', implode(', ', $kinds)));
        $io->newLine();

        // Display stats before starting
        try {
            $stats = $this->projector->getEventStats($kinds);
            $io->section('Current Database Stats');
            $io->writeln(sprintf('  Total events: <fg=cyan>%d</>', $stats['total']));
            foreach ($stats['by_kind'] as $kind => $count) {
                $kindName = match($kind) {
                    30040 => 'Magazines/Reading Lists',
                    30041 => 'Article Curation Sets',
                    default => "Kind $kind"
                };
                $io->writeln(sprintf('  - %s: <fg=cyan>%d</>', $kindName, $count));
            }
            $io->newLine();
        } catch (\Exception $e) {
            $io->warning('Could not retrieve event stats: ' . $e->getMessage());
        }

        try {
            // Start the long-lived subscription
            // This blocks forever and processes events via the callback
            $this->relayPool->subscribeLocalGenericEvents(
                $kinds,
                function (object $event, string $relayUrl) use ($io) {
                    $timestamp = date('Y-m-d H:i:s');
                    $eventId = substr($event->id ?? 'unknown', 0, 16) . '...';
                    $pubkey = substr($event->pubkey ?? 'unknown', 0, 16) . '...';
                    $kind = $event->kind ?? 'unknown';

                    $kindName = match($kind) {
                        30040 => 'Magazine/List',
                        30041 => 'Curation Set',
                        default => "Kind $kind"
                    };

                    // Extract title or d-tag for display
                    $title = '';
                    $dTag = '';
                    if (isset($event->tags) && is_array($event->tags)) {
                        foreach ($event->tags as $tag) {
                            if (is_array($tag) && isset($tag[0], $tag[1])) {
                                if ($tag[0] === 'title') {
                                    $title = mb_substr($tag[1], 0, 50);
                                    if (mb_strlen($tag[1]) > 50) {
                                        $title .= '...';
                                    }
                                } elseif ($tag[0] === 'd') {
                                    $dTag = $tag[1];
                                }
                            }
                        }
                    }

                    $displayInfo = $title ?: ($dTag ? "d:$dTag" : '');

                    // Log to console
                    $io->writeln(sprintf(
                        '[%s] <fg=green>Event received:</> %s (%s, pubkey: %s)%s',
                        $timestamp,
                        $eventId,
                        $kindName,
                        $pubkey,
                        $displayInfo ? ' - ' . $displayInfo : ''
                    ));

                    // Project the event to the database
                    try {
                        $this->projector->projectEventFromNostrEvent($event, $relayUrl);
                        $io->writeln(sprintf(
                            '[%s] <fg=green>✓</> Event saved to database',
                            date('Y-m-d H:i:s')
                        ));
                    } catch (\InvalidArgumentException $e) {
                        // Invalid event
                        $io->writeln(sprintf(
                            '[%s] <fg=yellow>⚠</> Skipped invalid event: %s',
                            date('Y-m-d H:i:s'),
                            $e->getMessage()
                        ));
                    } catch (\Exception $e) {
                        // Database or other errors
                        $io->writeln(sprintf(
                            '[%s] <fg=red>✗</> Error saving event: %s',
                            date('Y-m-d H:i:s'),
                            $e->getMessage()
                        ));
                    }

                    $io->newLine();
                }
            );

            // @phpstan-ignore-next-line - This line should never be reached (infinite loop in subscribeLocalGenericEvents)
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Subscription failed: ' . $e->getMessage());
            $this->logger->error('Magazine hydration worker failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }
}
