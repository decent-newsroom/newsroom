<?php

declare(strict_types=1);

namespace App\ChatBundle\Security;

use App\ChatBundle\Entity\ChatUser;
use App\ChatBundle\Enum\ChatRole;
use App\ChatBundle\Repository\ChatCommunityMembershipRepository;
use App\ChatBundle\Service\ChatSessionManager;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

class ChatUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly ChatSessionManager $sessionManager,
        private readonly ChatCommunityMembershipRepository $membershipRepo,
    ) {}

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        // The identifier in our case is the plaintext session token
        $session = $this->sessionManager->validateSession($identifier);

        if ($session === null) {
            throw new UserNotFoundException('Invalid or expired chat session');
        }

        $user = $session->getUser();
        $community = $session->getCommunity();

        // Set runtime roles based on community membership
        $membership = $this->membershipRepo->findByUserAndCommunity($user, $community);
        $runtimeRoles = [];
        if ($membership !== null) {
            match ($membership->getRole()) {
                ChatRole::ADMIN => $runtimeRoles[] = 'ROLE_CHAT_ADMIN',
                ChatRole::GUARDIAN => $runtimeRoles[] = 'ROLE_CHAT_GUARDIAN',
                default => null,
            };
        }
        $user->setRuntimeRoles($runtimeRoles);

        // Touch last seen
        $this->sessionManager->touchLastSeen($session);

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof ChatUser) {
            throw new UnsupportedUserException();
        }
        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return $class === ChatUser::class || is_subclass_of($class, ChatUser::class);
    }
}

