<?php

namespace App\EventListener;

use App\Entity\User;
use App\Service\UserMetadataSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener(event: LoginSuccessEvent::class)]
class UserMetadataSyncListener
{
    public function __construct(
        private readonly UserMetadataSyncService $syncService,
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
    }
}

