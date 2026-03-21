<?php

namespace App\Command;

use App\Enum\KindsEnum;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrRelayPool;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Subscription worker that hydrates user context events from the local strfry relay.
 *
 * Subscribes to all user-context event kinds that are NOT already handled by
 * the article, media, or magazine workers:
 *   - kind 0     — metadata (NIP-01)
 *   - kind 3     — follow list (NIP-02)
 *   - kind 5     — deletion requests (NIP-09)
 *   - kind 10000 — mute list (NIP-51)
 *   - kind 10001 — pin list (NIP-51)
 *   - kind 10002 — relay list (NIP-65)
 *   - kind 10003 — bookmarks (NIP-51)
 *   - kind 10015 — interests (NIP-51)
 *   - kind 10020 — media follows (NIP-68)
 *   - kind 10063 — Blossom server list (NIP-B7)
 *   - kind 30003 — bookmark sets (NIP-51)
 *   - kind 30004 — curation sets — articles (NIP-51)
 *   - kind 30005 — curation sets — videos (NIP-51)
 *   - kind 30006 — curation sets — pictures (NIP-51)
 *   - kind 30015 — interest sets (NIP-51)
 *   - kind 30024 — long-form drafts (NIP-23)
 *   - kind 34139 — playlists
 *   - kind 39089 — follow packs
 *
 * Events arrive in strfry via:
 *   - SyncUserEventsHandler (login sync → forward to strfry)
 *   - strfry router (external relay ingestion of user_data kinds)
 *   - Direct relay writes
 *
 * This worker persists them to the PostgreSQL event table using
 * GenericEventProjector, making them available for DB-only lookups
 * in controllers (follows tab, bookmarks page, settings, etc.).
 */
#[AsCommand(
    name: 'user-context:subscribe-local-relay',
    description: 'Subscribe to local relay for user context events and save them to the database in real-time'
)]
class SubscribeLocalUserContextCommand extends Command
{
    /**
     * User context kinds to subscribe to.
     *
     * KindBundles::USER_CONTEXT plus additional kinds that controllers look up
     * from the DB but no other worker handles.
     *
     * Excludes kinds already handled by other workers:
     *   - 30023 (articles) → SubscribeLocalRelayCommand
     *   - 20/21/22 (media)  → SubscribeLocalMediaCommand
     *   - 30040 (magazines) → SubscribeLocalMagazinesCommand
     */
    private const SUBSCRIBE_KINDS = [
        KindsEnum::METADATA->value,            // 0
        KindsEnum::FOLLOWS->value,             // 3
        KindsEnum::DELETION_REQUEST->value,    // 5
        KindsEnum::MUTE_LIST->value,           // 10000
        KindsEnum::PIN_LIST->value,            // 10001
        KindsEnum::RELAY_LIST->value,          // 10002
        KindsEnum::BOOKMARKS->value,           // 10003
        KindsEnum::INTERESTS->value,           // 10015
        KindsEnum::MEDIA_FOLLOWS->value,       // 10020
        KindsEnum::BLOSSOM_SERVER_LIST->value,  // 10063
        KindsEnum::BOOKMARK_SETS->value,       // 30003
        KindsEnum::CURATION_SET->value,        // 30004
        KindsEnum::CURATION_VIDEOS->value,     // 30005
        KindsEnum::CURATION_PICTURES->value,   // 30006
        KindsEnum::INTEREST_SETS->value,       // 30015
        KindsEnum::LONGFORM_DRAFT->value,      // 30024
        KindsEnum::PLAYLIST->value,            // 34139
        KindsEnum::FOLLOW_PACK->value,         // 39089
    ];

    /** Human-readable labels for console output. */
    private const KIND_LABELS = [
        0     => 'Metadata',
        3     => 'Follows',
        5     => 'Deletion requests',
        10000 => 'Mute list',
        10001 => 'Pin list',
        10002 => 'Relay list',
        10003 => 'Bookmarks',
        10015 => 'Interests',
        10020 => 'Media follows',
        10063 => 'Blossom servers',
        30003 => 'Bookmark sets',
        30004 => 'Curation sets (articles)',
        30005 => 'Curation sets (videos)',
        30006 => 'Curation sets (pictures)',
        30015 => 'Interest sets',
        30024 => 'Long-form drafts',
        34139 => 'Playlists',
        39089 => 'Follow packs',
    ];

    public function __construct(
        private readonly NostrRelayPool $relayPool,
        private readonly GenericEventProjector $projector,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            'This command subscribes to the local Nostr relay for user context events ' .
            '(follows, bookmarks, interests, relay lists, etc.) and automatically ' .
            'persists them to the database. It runs as a long-lived daemon process.' . "\n\n" .
            'These events are forwarded to strfry by the login sync handler but are ' .
            'not handled by the article, media, or magazine subscription workers. ' .
            'Without this worker, DB-only lookups in controllers would find nothing.'
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

        $io->title('User Context Hydration Worker');
        $io->info(sprintf('Subscribing to local relay: %s', $localRelay));
        $io->info(sprintf('Listening for %d user context event kinds...', count(self::SUBSCRIBE_KINDS)));
        $io->newLine();

        // Display subscribed kinds
        $io->section('Subscribed Kinds');
        foreach (self::SUBSCRIBE_KINDS as $kind) {
            $label = self::KIND_LABELS[$kind] ?? "Kind $kind";
            $io->writeln(sprintf('  - %s (kind %d)', $label, $kind));
        }
        $io->newLine();

        // Display current stats
        try {
            $stats = $this->projector->getEventStats(self::SUBSCRIBE_KINDS);
            $io->section('Current Database Stats');
            $io->writeln(sprintf('  Total user context events: <fg=cyan>%d</>', $stats['total']));
            foreach ($stats['by_kind'] as $kind => $count) {
                $label = self::KIND_LABELS[$kind] ?? "Kind $kind";
                $io->writeln(sprintf('  - %s: <fg=cyan>%d</>', $label, $count));
            }
            $io->newLine();
        } catch (\Exception $e) {
            $io->warning('Could not retrieve event stats: ' . $e->getMessage());
        }

        try {
            $this->relayPool->subscribeLocal(
                self::SUBSCRIBE_KINDS,
                function (object $event, string $relayUrl) use ($io) {
                    $timestamp = date('Y-m-d H:i:s');
                    $eventId = substr($event->id ?? 'unknown', 0, 16) . '...';
                    $pubkey = substr($event->pubkey ?? 'unknown', 0, 16) . '...';
                    $kind = $event->kind ?? 0;
                    $kindLabel = self::KIND_LABELS[$kind] ?? "Kind $kind";

                    $io->writeln(sprintf(
                        '[%s] <fg=green>Event received:</> %s (%s, pubkey: %s)',
                        $timestamp,
                        $eventId,
                        $kindLabel,
                        $pubkey
                    ));

                    try {
                        $this->projector->projectEventFromNostrEvent($event, $relayUrl);
                        $io->writeln(sprintf(
                            '[%s] <fg=green>✓</> Event saved to database',
                            date('Y-m-d H:i:s')
                        ));
                    } catch (\InvalidArgumentException $e) {
                        $io->writeln(sprintf(
                            '[%s] <fg=yellow>⚠</> Skipped invalid event: %s',
                            date('Y-m-d H:i:s'),
                            $e->getMessage()
                        ));
                    } catch (\Exception $e) {
                        $io->writeln(sprintf(
                            '[%s] <fg=red>✗</> Error saving event: %s',
                            date('Y-m-d H:i:s'),
                            $e->getMessage()
                        ));
                    }

                    $io->newLine();
                },
                'user-context'
            );

            // @phpstan-ignore-next-line - This line should never be reached (infinite loop in subscribeLocal)
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Subscription failed: ' . $e->getMessage());
            $this->logger->error('User context hydration worker failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }
    }
}



