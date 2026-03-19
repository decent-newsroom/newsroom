<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\WarmFollowsRelayPoolMessage;
use App\Service\Nostr\FollowsRelayPoolService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Proactively builds the per-user follows relay pool after login sync.
 *
 * Runs on async_low_priority so it doesn't block the login response.
 * By the time this runs, SyncUserEventsHandler has already forwarded
 * the user's kind 3 (follows) event to strfry and the relay workers
 * have persisted it to the DB.
 */
#[AsMessageHandler]
class WarmFollowsRelayPoolHandler
{
    public function __construct(
        private readonly FollowsRelayPoolService $poolService,
        private readonly LoggerInterface         $logger,
    ) {}

    public function __invoke(WarmFollowsRelayPoolMessage $message): void
    {
        $pubkey = $message->pubkeyHex;

        $this->logger->info('WarmFollowsRelayPoolHandler: warming pool', [
            'pubkey' => substr($pubkey, 0, 8) . '...',
        ]);

        try {
            $this->poolService->warmPool($pubkey);
        } catch (\Throwable $e) {
            $this->logger->warning('WarmFollowsRelayPoolHandler: pool warming failed', [
                'pubkey' => substr($pubkey, 0, 8) . '...',
                'error'  => $e->getMessage(),
            ]);
        }
    }
}

