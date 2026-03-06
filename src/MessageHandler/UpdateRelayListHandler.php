<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GatewayWarmConnectionsMessage;
use App\Message\SyncUserEventsMessage;
use App\Message\UpdateRelayListMessage;
use App\Service\Nostr\RelayHealthStore;
use App\Service\Nostr\UserRelayListService;
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
        private readonly RelayHealthStore $healthStore,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
        private readonly bool $gatewayEnabled = false,
    ) {}

    public function __invoke(UpdateRelayListMessage $message): void
    {
        $pubkey = $message->pubkeyHex;

        $this->logger->info('UpdateRelayListHandler: warming relay list', [
            'pubkey' => substr($pubkey, 0, 16) . '...',
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
            $this->dispatchEventSync($pubkey);

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
            $allRelays = $relays['all'] ?? [];

            // Filter to relays known to require AUTH
            $authRelays = array_values(array_filter(
                $allRelays,
                fn(string $url) => $this->healthStore->isAuthRequired($url),
            ));

            if (empty($authRelays)) {
                $this->logger->debug('UpdateRelayListHandler: no AUTH-gated relays to warm', [
                    'pubkey' => substr($pubkey, 0, 16) . '...',
                ]);
                return;
            }

            $this->messageBus->dispatch(new GatewayWarmConnectionsMessage($pubkey, $authRelays));

            $this->logger->info('UpdateRelayListHandler: dispatched gateway warm', [
                'pubkey' => substr($pubkey, 0, 16) . '...',
                'auth_relay_count' => count($authRelays),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('UpdateRelayListHandler: gateway warm dispatch failed', [
                'pubkey' => substr($pubkey, 0, 16) . '...',
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function dispatchEventSync(string $pubkey): void
    {
        try {
            // Pass the freshly-warmed read relay list so the handler doesn't
            // have to resolve it again from scratch.
            $relays = $this->relayListService->getRelayList($pubkey);
            $readRelays = $relays['read'] ?? $relays['all'] ?? [];

            $this->messageBus->dispatch(new SyncUserEventsMessage($pubkey, $readRelays));

            $this->logger->info('UpdateRelayListHandler: dispatched event sync', [
                'pubkey'      => substr($pubkey, 0, 16) . '...',
                'relay_count' => count($readRelays),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('UpdateRelayListHandler: event sync dispatch failed', [
                'pubkey' => substr($pubkey, 0, 16) . '...',
                'error'  => $e->getMessage(),
            ]);
        }
    }
}

