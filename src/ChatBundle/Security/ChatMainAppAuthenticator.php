<?php

declare(strict_types=1);

namespace App\ChatBundle\Security;

use App\ChatBundle\Entity\ChatUser;
use App\ChatBundle\Enum\ChatRole;
use App\ChatBundle\Repository\ChatCommunityMembershipRepository;
use App\ChatBundle\Repository\ChatUserRepository;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates self-sovereign chat admins by reading the main-app Symfony session.
 *
 * When a logged-in main-app User (with a cross-subdomain session cookie) visits a chat
 * subdomain, this authenticator looks up the linked ChatUser via the mainAppUser FK.
 * If found, the user is authenticated as that ChatUser with their community roles.
 *
 * Priority: this authenticator runs BEFORE ChatSessionAuthenticator. If it doesn't
 * support the request (no main-app session, or no linked ChatUser), it returns
 * supports=false and the cookie-based ChatSessionAuthenticator takes over.
 */
class ChatMainAppAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly ChatUserRepository $chatUserRepo,
        private readonly ChatCommunityMembershipRepository $membershipRepo,
    ) {}

    public function supports(Request $request): ?bool
    {
        // Only fire for chat community requests
        if (!$request->attributes->has('_chat_community')) {
            return false;
        }

        // Check if the main-app session contains an authenticated User
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session === null) {
            return false;
        }

        // Symfony stores the security token under _security_main (for the 'main' firewall)
        $serializedToken = $session->get('_security_main');
        if ($serializedToken === null) {
            return false;
        }

        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $session = $request->getSession();
        $serializedToken = $session->get('_security_main');

        if ($serializedToken === null) {
            throw new AuthenticationException('No main-app session token');
        }

        $token = unserialize($serializedToken);
        $mainAppUser = $token?->getUser();

        if (!$mainAppUser instanceof User) {
            throw new AuthenticationException('Main-app session does not contain a valid User');
        }

        $community = $request->attributes->get('_chat_community');
        $chatUser = $this->chatUserRepo->findByMainAppUserAndCommunity($mainAppUser, $community);

        if ($chatUser === null || !$chatUser->isActive()) {
            throw new AuthenticationException('No active chat account linked to this main-app user');
        }

        // Set runtime roles based on community membership
        $membership = $this->membershipRepo->findByUserAndCommunity($chatUser, $community);
        $runtimeRoles = [];
        if ($membership !== null) {
            match ($membership->getRole()) {
                ChatRole::ADMIN => $runtimeRoles[] = 'ROLE_CHAT_ADMIN',
                ChatRole::GUARDIAN => $runtimeRoles[] = 'ROLE_CHAT_GUARDIAN',
                default => null,
            };
        }
        $chatUser->setRuntimeRoles($runtimeRoles);

        return new SelfValidatingPassport(
            new UserBadge($chatUser->getPubkey(), fn () => $chatUser)
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Let the request continue to the controller
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Don't block — return null so the next authenticator (ChatSessionAuthenticator) can try
        return null;
    }
}

