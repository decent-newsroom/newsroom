<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Service;

use DecentNewsroom\IdentityBundle\Entity\UserIdentityLink;
use DecentNewsroom\IdentityBundle\Exception\IdentityAlreadyLinkedException;
use DecentNewsroom\IdentityBundle\Exception\LastIdentityException;
use DecentNewsroom\IdentityBundle\Repository\UserIdentityLinkRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Manages linking/unlinking identities on an already-authenticated user's
 * account (the "Linked identities" settings panel), as opposed to
 * {@see \DecentNewsroom\IdentityBundle\Contract\UserRepositoryBridgeInterface},
 * which resolves identities to users during login.
 */
final class IdentityLinkingService
{
    public function __construct(
        private readonly UserIdentityLinkRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return UserIdentityLink[]
     */
    public function listIdentities(string $ownerId): array
    {
        return $this->repository->findAllByOwnerId($ownerId);
    }

    /**
     * @throws IdentityAlreadyLinkedException if externalId is already claimed by a different owner
     */
    public function link(string $ownerId, string $provider, string $externalId, ?string $label = null): UserIdentityLink
    {
        $existing = $this->repository->findOneByProviderAndExternalId($provider, $externalId);

        if ($existing !== null && $existing->getOwnerId() !== $ownerId) {
            throw IdentityAlreadyLinkedException::forExternalId($provider, $externalId);
        }

        if ($existing !== null) {
            // Already linked to this same owner — treat as a no-op, just refresh the label.
            if ($label !== null) {
                $existing->setLabel($label);
                $this->entityManager->flush();
            }

            return $existing;
        }

        $link = new UserIdentityLink($ownerId, $provider, $externalId);
        $link->setLabel($label);

        $this->entityManager->persist($link);
        $this->entityManager->flush();

        return $link;
    }

    /**
     * @throws LastIdentityException if this would remove the owner's last remaining identity
     */
    public function unlink(string $ownerId, int $linkId): void
    {
        if ($this->repository->countByOwnerId($ownerId) <= 1) {
            throw LastIdentityException::forOwnerId($ownerId);
        }

        $link = $this->repository->find($linkId);

        if ($link === null || $link->getOwnerId() !== $ownerId) {
            return;
        }

        $this->entityManager->remove($link);
        $this->entityManager->flush();
    }
}
