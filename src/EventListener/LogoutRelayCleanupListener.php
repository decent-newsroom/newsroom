<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use App\Service\Nostr\Nip46SessionService;
use App\Service\Nostr\RelayGatewayClient;
use App\Util\NostrKeyUtil;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * On logout, tell the relay gateway to close all user-specific connections
 * and remove the stored NIP-46 session credentials (if any).
 */
#[AsEventListener(event: LogoutEvent::class)]
class LogoutRelayCleanupListener
{
    public function __construct(
        private readonly RelayGatewayClient $gatewayClient,
        private readonly Nip46SessionService $nip46Sessions,
        private readonly LoggerInterface $logger,
        private readonly bool $gatewayEnabled = false,
    ) {}

    public function __invoke(LogoutEvent $event): void
    {
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

            // Always clear NIP-46 session on logout (irrespective of gateway flag)
            $this->nip46Sessions->remove($hex);

            if ($this->gatewayEnabled) {
                $this->gatewayClient->closeUserConnections($hex);
                $this->logger->info('LogoutRelayCleanupListener: dispatched gateway close', [
                    'npub' => substr($npub, 0, 16) . '...',
                ]);
            }
        } catch (\Throwable $e) {
            // Don't fail the logout
            $this->logger->warning('LogoutRelayCleanupListener: failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

