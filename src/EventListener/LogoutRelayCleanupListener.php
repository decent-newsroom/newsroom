<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\Nostr\RelayGatewayClient;
use App\Util\NostrKeyUtil;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * On logout, tell the relay gateway to close all user-specific connections.
 *
 * This frees authenticated WebSocket connections that were opened for
 * the user's AUTH-gated relays. Shared connections are unaffected.
 */
#[AsEventListener(event: LogoutEvent::class)]
class LogoutRelayCleanupListener
{
    public function __construct(
        private readonly RelayGatewayClient $gatewayClient,
        private readonly LoggerInterface $logger,
        private readonly bool $gatewayEnabled = false,
    ) {}

    public function __invoke(LogoutEvent $event): void
    {
        if (!$this->gatewayEnabled) {
            return;
        }

        try {
            $user = $event->getToken()?->getUser();
            if (!$user instanceof User) {
                return;
            }

            $npub = $user->getNpub();
            if (!$npub || !NostrKeyUtil::isNpub($npub)) {
                return;
            }

            $hex = NostrKeyUtil::npubToHex($npub);
            $this->gatewayClient->closeUserConnections($hex);

            $this->logger->info('LogoutRelayCleanupListener: dispatched gateway close', [
                'npub' => substr($npub, 0, 16) . '...',
            ]);
        } catch (\Throwable $e) {
            // Don't fail the logout
            $this->logger->warning('LogoutRelayCleanupListener: failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

