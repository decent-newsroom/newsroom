<?php

declare(strict_types=1);

namespace App\ChatBundle\Security;

use App\ChatBundle\Service\ChatSessionManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

class ChatSessionAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function supports(Request $request): ?bool
    {
        // Only fire for chat community requests that have the cookie
        if (!$request->attributes->has('_chat_community')) {
            return false;
        }

        $token = $request->cookies->get(ChatSessionManager::getCookieName());
        return $token !== null;
    }

    public function authenticate(Request $request): Passport
    {
        $token = $request->cookies->get(ChatSessionManager::getCookieName());

        if ($token === null) {
            throw new AuthenticationException('No chat session cookie');
        }

        // The UserProvider (ChatUserProvider) does the actual session validation
        return new SelfValidatingPassport(
            new UserBadge($token)
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Let the request continue to the controller
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Redirect to invite-required page
        return new RedirectResponse('/activate/required');
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        // Entry point — shown when accessing a protected chat page without a session
        return new Response(
            '<html><body><h1>Invite Required</h1><p>You need an invite link to access this chat community.</p></body></html>',
            Response::HTTP_UNAUTHORIZED,
            ['Content-Type' => 'text/html']
        );
    }
}

