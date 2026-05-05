<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\KindsEnum;
use App\Message\FetchRelayMonitorEventsMessage;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrRequestExecutor;
use App\Service\Nostr\RelayRegistry;
use App\Service\Nostr\RelaySetFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Fetches NIP-66 relay monitor events (kinds 10166 + 30166) from the Nostr
 * network for a given monitor pubkey and projects them into the local database.
 *
 * Triggered automatically when a monitor is trusted via the admin UI, and can
 * also be invoked manually via `relay:fetch-monitor-events <pubkey>`.
 */
#[AsMessageHandler]
final class FetchRelayMonitorEventsHandler
{
    public function __construct(
        private readonly NostrRequestExecutor  $executor,
        private readonly RelaySetFactory       $relaySetFactory,
        private readonly RelayRegistry         $relayRegistry,
        private readonly GenericEventProjector $projector,
        private readonly LoggerInterface       $logger,
    ) {}

    public function __invoke(FetchRelayMonitorEventsMessage $message): void
    {
        $pubkey = $message->pubkeyHex;

        $this->logger->info('NIP-66: fetching monitor events from external relays', [
            'pubkey' => substr($pubkey, 0, 16) . '…',
        ]);

        // Use content relays for discovery — monitor bots typically publish there.
        $contentRelayUrls = $this->relayRegistry->getContentRelays();
        if (empty($contentRelayUrls)) {
            $this->logger->warning('NIP-66: no content relays configured, cannot fetch monitor events');
            return;
        }

        $relaySet = $this->relaySetFactory->fromUrls($contentRelayUrls);

        $kinds   = [KindsEnum::RELAY_MONITOR_ANNOUNCEMENT->value, KindsEnum::RELAY_DISCOVERY->value];
        $filters = [
            'authors' => [$pubkey],
            'limit'   => 1000,
        ];

        // Use a generous gateway timeout: 30166 events can be numerous (one per
        // monitored relay) so we give the relay extra time to stream them all.
        $events = $this->executor->fetch($kinds, $filters, $relaySet, null, $pubkey, 20);

        $this->logger->info('NIP-66: received events from relays', [
            'pubkey' => substr($pubkey, 0, 16) . '…',
            'count'  => count($events),
        ]);

        $projected = 0;
        $skipped   = 0;

        foreach ($events as $rawEvent) {
            try {
                $this->projector->projectEventFromNostrEvent($rawEvent, 'nip66-fetch');
                $projected++;
            } catch (\Throwable $e) {
                // "Event already exists" is the common case for re-runs — fine.
                $skipped++;
                $this->logger->debug('NIP-66: skipped event during projection', [
                    'event_id' => $rawEvent->id ?? 'unknown',
                    'reason'   => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('NIP-66: monitor event fetch complete', [
            'pubkey'    => substr($pubkey, 0, 16) . '…',
            'projected' => $projected,
            'skipped'   => $skipped,
        ]);
    }
}

