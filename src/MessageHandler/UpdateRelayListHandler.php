<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GatewayWarmConnectionsMessage;
use App\Message\SyncUserEventsMessage;
use App\Message\UpdateRelayListMessage;
use App\Service\Nostr\RelayHealthStore;
use App\Service\Nostr\RelayRegistry;
use App\Service\Nostr\UserRelayListService;
use App\Util\RelayUrlNormalizer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handles async relay list warming dispatched on user login.
 *
 * Calls UserRelayListService::revalidate() which does a blocking network
 * fetch from profile relays, persists the result to the DB (write-through),
 * and warms the PSR-6 cache. Runs on the low-priority transport so it
 * doesn't block the main queue.
 *
 * After warming, dispatches GatewayWarmConnectionsMessage for any AUTH-gated
 * relays in the user's list, so the relay gateway can open persistent
 * authenticated connections.
 */
#[AsMessageHandler]
class UpdateRelayListHandler
{
    public function __construct(
        private readonly UserRelayListService $relayListService,
        private readonly RelayRegistry $relayRegistry,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly bool $gatewayEnabled = false,
    ) {}

    public function __invoke(UpdateRelayListMessage $message): void
    {
        $pubkey = $message->pubkeyHex;

        $this->logger->info('UpdateRelayListHandler: warming relay list', [
            'pubkey' => substr($pubkey, 0, 16) . '...',
            'full_sync' => $message->fullSync,
        ]);

        try {
            $this->relayListService->revalidate($pubkey);

            $this->logger->info('UpdateRelayListHandler: relay list warmed', [
                'pubkey' => substr($pubkey, 0, 16) . '...',
            ]);

            // If gateway is enabled, warm user connections to AUTH-gated relays
            if ($this->gatewayEnabled) {
                $this->dispatchGatewayWarm($pubkey);
            }

            // Dispatch batch event sync — relay list is now warm so relays are known
            $this->dispatchEventSync($pubkey, $message->fullSync);

        } catch (\Throwable $e) {
            $this->logger->warning('UpdateRelayListHandler: revalidation failed', [
                'pubkey' => substr($pubkey, 0, 16) . '...',
                'error' => $e->getMessage(),
            ]);
            // Don't rethrow — this is best-effort warming, not critical
        }
    }

    private function dispatchGatewayWarm(string $pubkey): void
    {
        try {
            $relays = $this->relayListService->getRelayList($pubkey);

            // Warm ALL write relays, not just those already known to require AUTH.
            // The health store only knows about relays the gateway has previously
            // connected to — a new user or fresh install has no history, so
            // filtering to isAuthRequired() would always produce an empty list.
            // The gateway will open connections and discover AUTH requirements
            // naturally as it receives AUTH challenges from each relay.
            $writeRelays = array_values(array_unique(array_merge(
                $relays['write'] ?? [],
                $relays['all'] ?? [],
            )));

            // Exclude the local relay — it's always connected as a shared
            // connection and never needs user-specific warming.
            $localRelay = $this->relayRegistry->getLocalRelay();
            if ($localRelay !== null) {
                $writeRelays = array_values(array_filter(
                    $writeRelays,
                    fn(string $url) => !RelayUrlNormalizer::equals($url, $localRelay),
                ));
            }

            if (empty($writeRelays)) {
                $this->logger->debug('UpdateRelayListHandler: no write relays to warm', [
                    'pubkey' => substr($pubkey, 0, 16) . '...',
                ]);
                return;
            }

            $this->messageBus->dispatch(new GatewayWarmConnectionsMessage($pubkey, $writeRelays));

            $this->logger->info('UpdateRelayListHandler: dispatched gateway warm', [
                'pubkey'      => substr($pubkey, 0, 16) . '...',
                'relay_count' => count($writeRelays),
                'relays'      => $writeRelays,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('UpdateRelayListHandler: gateway warm dispatch failed', [
                'pubkey' => substr($pubkey, 0, 16) . '...',
                'error'  => $e->getMessage(),
            ]);
        }
    }

    private function dispatchEventSync(string $pubkey, bool $fullSync = false): void
    {
        try {
            // Pass the freshly-warmed read relay list so the handler doesn't
            // have to resolve it again from scratch.
            $relays = $this->relayListService->getRelayList($pubkey);
            $readRelays = $relays['read'] ?? $relays['all'] ?? [];

            // Full sync: no time restriction — fetches all replaceable events
            // (kind 0, 3, 10002, etc.) regardless of age.
            // Login sync: only last 24 hours to reduce relay load.
            $since = $fullSync ? 0 : time() - 86400;

            $this->messageBus->dispatch(new SyncUserEventsMessage($pubkey, $readRelays, $since));

            $this->logger->info('UpdateRelayListHandler: dispatched event sync', [
                'pubkey'      => substr($pubkey, 0, 16) . '...',
                'relay_count' => count($readRelays),
                'full_sync'   => $fullSync,
                'since'       => $since ?: 'all-time',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('UpdateRelayListHandler: event sync dispatch failed', [
                'pubkey' => substr($pubkey, 0, 16) . '...',
                'error'  => $e->getMessage(),
            ]);
        }
    }
}

