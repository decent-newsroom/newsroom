<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserEntityRepository;
use App\Service\Nostr\NostrIdentityService;
use DecentNewsroom\IdentityBundle\Contract\IdentityOwnerInterface;
use DecentNewsroom\IdentityBundle\Contract\UserRepositoryBridgeInterface;
use DecentNewsroom\IdentityBundle\Entity\UserIdentityLink;
use DecentNewsroom\IdentityBundle\Repository\UserIdentityLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Bridges DecentNewsroom\IdentityBundle to this application's own user
 * persistence layer (App\Entity\User), so the bundle never needs to know
 * about our concrete entity/repository.
 *
 * Preserves 100% backward compatibility for existing Nostr-only accounts:
 * `npub` stays the Security identifier for anyone who has one, and a
 * pre-existing User (found by npub, from before IdentityBundle existed) is
 * transparently linked on first login rather than duplicated.
 */
final readonly class AppIdentityBridge implements UserRepositoryBridgeInterface
{
    public function __construct(
        private UserEntityRepository $userRepository,
        private UserIdentityLinkRepository $links,
        private NostrIdentityService $nostrIdentity,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findOrCreateByIdentity(string $provider, string $externalId): IdentityOwnerInterface
    {
        $link = $this->links->findOneByProviderAndExternalId($provider, $externalId);

        if ($link !== null) {
            return $this->loadByOwnerId($link->getOwnerId());
        }

        $user = $this->findExistingUserForFirstLogin($provider, $externalId);

        if ($user === null) {
            $user = new User();
            if ($provider === 'nostr') {
                $user->setNpub($this->nostrIdentity->encodeNpub($externalId));
            } else {
                $user->setLocalIdentifier((string) new Ulid());
            }
            $this->entityManager->persist($user);
            $this->entityManager->flush(); // need the generated id for getIdentityOwnerId()
        }

        $newLink = new UserIdentityLink(
            $user->getIdentityOwnerId(),
            $provider,
            $externalId,
        );
        $newLink->markVerified();
        $this->entityManager->persist($newLink);
        $this->entityManager->flush();

        return $user;
    }

    public function loadByOwnerId(string $ownerId): IdentityOwnerInterface
    {
        $user = $this->userRepository->find((int) $ownerId);

        if ($user === null) {
            throw new \RuntimeException(sprintf('No user found for owner id "%s".', $ownerId));
        }

        return $user;
    }

    public function loadByUserIdentifier(string $identifier): IdentityOwnerInterface
    {
        $user = $this->userRepository->findOneBy(['npub' => $identifier])
            ?? $this->userRepository->findOneBy(['localIdentifier' => $identifier]);

        if ($user === null) {
            throw new \RuntimeException(sprintf('No user found for identifier "%s".', $identifier));
        }

        return $user;
    }

    /**
     * Backward-compatibility path: if this is a Nostr login and a User row
     * already exists with this npub (created before IdentityBundle/UserIdentityLink
     * existed, or by identity:backfill-nostr-links), reuse it instead of creating
     * a duplicate account.
     */
    private function findExistingUserForFirstLogin(string $provider, string $externalId): ?User
    {
        if ($provider !== 'nostr') {
            return null;
        }

        $npub = $this->nostrIdentity->encodeNpub($externalId);

        return $this->userRepository->findOneBy(['npub' => $npub]);
    }
}
