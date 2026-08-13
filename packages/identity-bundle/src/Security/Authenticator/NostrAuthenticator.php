<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Security\Authenticator;

use DecentNewsroom\IdentityBundle\Contract\UserRepositoryBridgeInterface;
use DecentNewsroom\IdentityBundle\Security\IdentityProvider\NostrIdentityProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\InteractiveAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

final class NostrAuthenticator extends AbstractAuthenticator implements InteractiveAuthenticatorInterface, AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly NostrIdentityProvider $identityProvider,
        private readonly UserRepositoryBridgeInterface $users,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $this->identityProvider->supports($request);
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $externalId = $this->identityProvider->authenticate($request);
        $user = $this->users->findOrCreateByIdentity($this->identityProvider->getName(), $externalId);

        return new SelfValidatingPassport(new UserBadge(
            $user->getUserIdentifier(),
            static fn (): UserInterface => $user,
        ));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        if ($request->getPathInfo() === '/login' && $request->headers->has('Authorization')) {
            return new Response(
                \json_encode(['message' => 'Authentication Successful'], \JSON_THROW_ON_ERROR),
                Response::HTTP_OK,
                ['Content-Type' => 'application/json'],
            );
        }

        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new Response(
            \json_encode(['error' => 'Authentication failed', 'message' => $exception->getMessage()], \JSON_THROW_ON_ERROR),
            Response::HTTP_UNAUTHORIZED,
            ['Content-Type' => 'application/json'],
        );
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $message = $authException?->getMessage() ?? 'Authentication required';
        $acceptHeader = $request->headers->get('Accept', '');
        $contentType = $request->headers->get('Content-Type', '');
        $isJsonRequest = \str_contains($acceptHeader, 'application/json')
            || \str_contains($contentType, 'application/json')
            || $request->isXmlHttpRequest();

        if ($isJsonRequest) {
            return new Response(
                \json_encode(['error' => 'Authentication required', 'message' => $message], \JSON_THROW_ON_ERROR),
                Response::HTTP_UNAUTHORIZED,
                ['Content-Type' => 'application/json'],
            );
        }

        return new Response(
            '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Authentication Required</title></head><body><h1>Authentication Required</h1><p>' . \htmlspecialchars($message) . '</p><p><a href="/login">Please log in to continue</a></p></body></html>',
            Response::HTTP_UNAUTHORIZED,
            ['Content-Type' => 'text/html'],
        );
    }

    public function isInteractive(): bool
    {
        return true;
    }
}

