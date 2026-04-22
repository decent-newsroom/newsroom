<?php

namespace App\Security;

use App\Entity\User;
use App\Message\UpdateProfileProjectionMessage;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Provides user data transfer object (DTO) operations for authentication and user management.
 *
 * This class is responsible for refreshing user data from the database and cache,
 * and for determining if a given class is supported by the provider.
 */
readonly class UserDTOProvider implements UserProviderInterface
{
    /**
     * Skip dispatching an async profile refresh on user refresh/login if we
     * already refreshed metadata within this window. Every authenticated
     * request triggers refreshUser(), so without this guard the
     * async_profiles queue grows 1:1 with request volume for logged-in users.
     */
    private const METADATA_REFRESH_WINDOW_SECONDS = 4 * 3600; // 4 hours

    public function __construct(
        private EntityManagerInterface    $entityManager,
        private LoggerInterface           $logger,
        private MessageBusInterface       $messageBus
    )
    {
    }

    /**
     * Refreshes the user by reloading it from the database.
     *
     * Metadata updates are handled asynchronously and won't block this operation.
     *
     * @param UserInterface $user The user to refresh.
     * @return UserInterface The refreshed user instance.
     * @throws \InvalidArgumentException If the provided user is not an instance of User.
     */
    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new \InvalidArgumentException('Invalid user type.');
        }

        try {
            $this->logger->info('Refresh user.', ['user' => $user->getUserIdentifier()]);

            // Reload user from database (fast operation)
            $freshUser = $this->entityManager->getRepository(User::class)
                ->findOneBy(['npub' => $user->getUserIdentifier()]);

            if (!$freshUser) {
                // Create minimal user entity if not found
                $freshUser = new User();
                $freshUser->setNpub($user->getUserIdentifier());
                $this->entityManager->persist($freshUser);
                $this->entityManager->flush();

                $this->logger->info('Created new user during refresh', ['npub' => $user->getUserIdentifier()]);
            }

            // Trigger async profile update only if metadata is older than the refresh window.
            // Without this guard, every authenticated request enqueues an
            // UpdateProfileProjectionMessage and floods async_profiles.
            try {
                $lastRefresh = $freshUser->getLastMetadataRefresh();
                $isFresh = $lastRefresh !== null
                    && (time() - $lastRefresh->getTimestamp()) < self::METADATA_REFRESH_WINDOW_SECONDS;

                if ($isFresh) {
                    $this->logger->debug('Skipping profile refresh dispatch; metadata is fresh', [
                        'npub' => $user->getUserIdentifier(),
                        'last_refresh' => $lastRefresh->format(\DateTimeInterface::ATOM),
                    ]);
                } else {
                    $pubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());
                    $this->messageBus->dispatch(new UpdateProfileProjectionMessage($pubkey));
                }
            } catch (\Throwable $e) {
                // Log but don't block refresh
                $this->logger->error('Exception on dispatch during refresh: ' . $e->getMessage(), ['npub' => $user->getUserIdentifier()]);
            }

            return $freshUser;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to refresh user, returning session user as-is', [
                'npub' => $user->getUserIdentifier(),
                'error' => $e->getMessage(),
            ]);
            // Return the original user from session rather than crashing → 502
            return $user;
        }
    }

    /**
     * @inheritDoc
     */
    public function supportsClass(string $class): bool
    {
        /**
         * Checks if the provider supports the given user class.
         *
         * @param string $class The class name to check.
         * @return bool True if the class is supported, false otherwise.
         */
        return $class === User::class;
    }

    /**
     * @inheritDoc
     * @throws ExceptionInterface
     */
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $this->logger->info('Load user by identifier.', ['identifier' => $identifier]);

        // Get or create user (fast DB operation)
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['npub' => $identifier]);

        if (!$user) {
            $user = new User();
            $user->setNpub($identifier);
            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $this->logger->info('Created new user', ['npub' => $identifier]);
        }

        // Convert npub to hex for async profile update
        try {
            $pubkey = NostrKeyUtil::npubToHex($identifier);

            // Only dispatch profile projection update on login if metadata is
            // stale. Prevents duplicate work with refreshUser() and avoids
            // flooding async_profiles when a user logs back in frequently.
            $lastRefresh = $user->getLastMetadataRefresh();
            $isFresh = $lastRefresh !== null
                && (time() - $lastRefresh->getTimestamp()) < self::METADATA_REFRESH_WINDOW_SECONDS;

            if (!$isFresh) {
                $this->messageBus->dispatch(new UpdateProfileProjectionMessage($pubkey));
                $this->logger->debug('Dispatched profile projection update', ['npub' => $identifier]);
            } else {
                $this->logger->debug('Skipping login profile dispatch; metadata is fresh', ['npub' => $identifier]);
            }
        } catch (\InvalidArgumentException|ExceptionInterface $e) {
            $this->logger->error('Invalid npub identifier.', ['npub' => $identifier]);
            throw $e;
        }

        // Return user immediately - metadata will be updated async
        return $user;
    }
}
