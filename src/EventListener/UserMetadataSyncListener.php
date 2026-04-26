<?php

namespace App\EventListener;

use App\Entity\User;
use App\Message\UpdateRelayListMessage;
use App\Service\UserMetadataSyncService;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

#[AsEventListener(event: LoginSuccessEvent::class)]
class UserMetadataSyncListener
{
    /** Minimum seconds between full relay syncs per user */
    private const SYNC_THROTTLE_SECONDS = 1800; // 30 minutes

    public function __construct(
        private readonly UserMetadataSyncService $syncService,
        private readonly MessageBusInterface $messageBus,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        // Track last login time
        $user->setLastLoginAt(new \DateTimeImmutable());
        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {
            $this->logger->warning('Failed to update lastLoginAt: ' . $e->getMessage());
        }

        try {
            // Sync metadata on login
            $this->syncService->syncUser($user);
        } catch (\Exception $e) {
            // Don't fail the login if metadata sync fails
            $this->logger->warning("Failed to sync metadata on login for user {$user->getNpub()}: " . $e->getMessage());
        }

        // Dispatch async relay list warming — throttled to avoid hammering relays
        // on repeated logins / tab refreshes.
        // Gateway warm is always dispatched (inside UpdateRelayListHandler) when
        // shouldSync is true. For throttled logins where shouldSync is false we
        // still re-warm gateway connections so any new AUTH challenges that arrived
        // since the last warm are handled by the now-persistent relay-auth controller.
        try {
            $npub = $user->getNpub();
            if ($npub && NostrKeyUtil::isNpub($npub)) {
                $lastRefresh = $user->getLastMetadataRefresh();
                $now = new \DateTimeImmutable();
                $shouldSync = $lastRefresh === null
                    || ($now->getTimestamp() - $lastRefresh->getTimestamp()) > self::SYNC_THROTTLE_SECONDS;

                if ($shouldSync) {
                    $hex = NostrKeyUtil::npubToHex($npub);
                    // First sync (never refreshed before): full fetch without time restriction
                    // so replaceable events (kind 0, 3, 10002…) are fetched regardless of age.
                    // Subsequent syncs: only last 24 hours to reduce relay load.
                    $fullSync = $lastRefresh === null;
                    $this->messageBus->dispatch(new UpdateRelayListMessage($hex, $fullSync));


                    $this->logger->debug('Dispatched relay list warming for user', [
                        'npub' => substr($npub, 0, 16) . '...',
                    ]);
                } else {
                    $this->logger->debug('Skipping relay list warming — recently synced', [
                        'npub' => substr($npub, 0, 16) . '...',
                        'last_refresh' => $lastRefresh->format('c'),
                    ]);
                }
            }
        } catch (\Exception|ExceptionInterface $e) {
            // Don't fail the login if dispatch fails
            $this->logger->warning('Failed to dispatch relay list warming: ' . $e->getMessage());
        }
    }
}

