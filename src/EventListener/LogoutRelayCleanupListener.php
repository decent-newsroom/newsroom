<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use DecentNewsroom\SigningBundle\Service\Nostr\Nip46SessionStore;
use App\Service\Nostr\RelayGatewayClient;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

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
        private readonly Nip46SessionStore $nip46Sessions,
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
            if (!$npub || !str_starts_with(strtolower(trim((string) ($npub))), 'npub1')) {
                return;
            }

            $hex = (static function (string $npub): string { $npub = strtolower(trim($npub)); if (str_starts_with($npub, 'nostr:')) { $npub = substr($npub, 6); } return PublicKey::fromBech32($npub)?->toHex() ?? throw new \InvalidArgumentException('Not a valid npub'); })((string) ($npub));

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


