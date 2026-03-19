<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Enum\RolesEnum;
use App\Repository\UserEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;

/**
 * Automatically promotes users to higher roles based on their publishing activity.
 *
 * - ROLE_WRITER is granted when a user publishes an article (kind 30023).
 * - ROLE_EDITOR is granted when a user publishes a reading list or magazine (kind 30040).
 *
 * Only promotes users that already have an account (have logged in before).
 * Skips silently when the user is unknown or already holds the target role.
 */
class UserRolePromoter
{
    public function __construct(
        private readonly UserEntityRepository $userRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Grant ROLE_WRITER to the author of a published article.
     *
     * @param string $pubkeyHex The author's public key in hex format
     */
    public function promoteToWriter(string $pubkeyHex): void
    {
        $this->promoteToRole($pubkeyHex, RolesEnum::WRITER);
    }

    /**
     * Grant ROLE_EDITOR to the author of a published reading list or magazine.
     *
     * @param string $pubkeyHex The author's public key in hex format
     */
    public function promoteToEditor(string $pubkeyHex): void
    {
        $this->promoteToRole($pubkeyHex, RolesEnum::EDITOR);
    }

    /**
     * Promote a user to the given role if they exist and don't already hold it.
     */
    private function promoteToRole(string $pubkeyHex, RolesEnum $role): void
    {
        if (empty($pubkeyHex)) {
            return;
        }

        try {
            $key = new Key();
            $npub = $key->convertPublicKeyToBech32($pubkeyHex);
        } catch (\Throwable $e) {
            $this->logger->debug('UserRolePromoter: could not convert pubkey to npub', [
                'pubkey' => substr($pubkeyHex, 0, 16) . '...',
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $user = $this->userRepository->findOneBy(['npub' => $npub]);
        if (!$user) {
            return; // User has never logged in — nothing to promote
        }

        if (in_array($role->value, $user->getRoles(), true)) {
            return; // Already has this role
        }

        $user->addRole($role->value);
        $this->em->flush();

        $this->logger->info('UserRolePromoter: granted role to user', [
            'npub' => $npub,
            'role' => $role->value,
        ]);
    }
}

