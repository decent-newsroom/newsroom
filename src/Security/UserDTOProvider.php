<?php

namespace App\Security;

use App\Entity\User;
use App\Service\RedisCacheService;
use App\Service\UserMetadataSyncService;
use App\Util\NostrKeyUtil;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
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
    public function __construct(
        private EntityManagerInterface    $entityManager,
        private RedisCacheService         $redisCacheService,
        private UserMetadataSyncService   $metadataSyncService,
        private LoggerInterface           $logger
    )
    {
    }

    /**
     * Refreshes the user by reloading it from the database and updating its metadata from cache.
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
        $this->logger->info('Refresh user.', ['user' => $user->getUserIdentifier()]);
        $freshUser = $this->entityManager->getRepository(User::class)
            ->findOneBy(['npub' => $user->getUserIdentifier()]);
        try {
            $pubkey = NostrKeyUtil::npubToHex($user->getUserIdentifier());
        } catch (\InvalidArgumentException $e) {
            $this->logger->error('Invalid npub identifier.', ['npub' => $user->getUserIdentifier()]);
            throw $e;
        }
        $metadata = $this->redisCacheService->getMetadata($pubkey);
        $freshUser->setMetadata($metadata);

        // Fetch relays from RedisCacheService and set on user
        $relays = $this->redisCacheService->getRelays($pubkey);
        $freshUser->setRelays($relays);

        // Sync metadata to database fields (will also trigger Elasticsearch indexing via listener)
        $this->metadataSyncService->syncUser($freshUser);

        return $freshUser;
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
     */
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $this->logger->info('Load user by identifier.', ['identifier' => $identifier]);
        // Get or create user
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['npub' => $identifier]);

        if (!$user) {
            $user = new User();
            $user->setNpub($identifier);
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        }

        try {
            $pubkey = NostrKeyUtil::npubToHex($identifier);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error('Invalid npub identifier.', ['npub' => $identifier]);
            throw $e;
        }
        $metadata = $this->redisCacheService->getMetadata($pubkey);
        $user->setMetadata($metadata);
        $this->logger->debug('User metadata set.', ['metadata' => json_encode($user->getMetadata())]);

        // Fetch relays from RedisCacheService and set on user
        $relays = $this->redisCacheService->getRelays($pubkey);
        $user->setRelays($relays);

        // Sync metadata to database fields (will also trigger Elasticsearch indexing via listener)
        $this->metadataSyncService->syncUser($user);

        return $user;
    }
}
