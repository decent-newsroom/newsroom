<?php

namespace App\EventListener;

use App\Entity\User;
use App\Message\UpdateRelayListMessage;
use App\Service\UserMetadataSyncService;
use App\Util\NostrKeyUtil;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener(event: LoginSuccessEvent::class)]
class UserMetadataSyncListener
{
    public function __construct(
        private readonly UserMetadataSyncService $syncService,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        try {
            // Sync metadata on login
            $this->syncService->syncUser($user);
        } catch (\Exception $e) {
            // Don't fail the login if metadata sync fails
            $this->logger->warning("Failed to sync metadata on login for user {$user->getNpub()}: " . $e->getMessage());
        }

        // Dispatch async relay list warming
        try {
            $npub = $user->getNpub();
            if ($npub && NostrKeyUtil::isNpub($npub)) {
                $hex = NostrKeyUtil::npubToHex($npub);
                $this->messageBus->dispatch(new UpdateRelayListMessage($hex));
                $this->logger->debug('Dispatched relay list warming for user', [
                    'npub' => substr($npub, 0, 16) . '...',
                ]);
            }
        } catch (\Exception $e) {
            // Don't fail the login if dispatch fails
            $this->logger->warning('Failed to dispatch relay list warming: ' . $e->getMessage());
        }
    }
}

