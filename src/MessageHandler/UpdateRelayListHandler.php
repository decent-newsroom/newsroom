<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\UpdateRelayListMessage;
use App\Service\Nostr\UserRelayListService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles async relay list warming dispatched on user login.
 *
 * Calls UserRelayListService::revalidate() which does a blocking network
 * fetch from profile relays, persists the result to the DB (write-through),
 * and warms the PSR-6 cache. Runs on the low-priority transport so it
 * doesn't block the main queue.
 */
#[AsMessageHandler]
class UpdateRelayListHandler
{
    public function __construct(
        private readonly UserRelayListService $relayListService,
        private readonly LoggerInterface $logger,
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
        } catch (\Throwable $e) {
            $this->logger->warning('UpdateRelayListHandler: revalidation failed', [
                'pubkey' => substr($pubkey, 0, 16) . '...',
                'error' => $e->getMessage(),
            ]);
            // Don't rethrow — this is best-effort warming, not critical
        }
    }
}

