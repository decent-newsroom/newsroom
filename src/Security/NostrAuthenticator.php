<?php

namespace App\Security;

use App\Entity\Event;
use Mdanter\Ecc\Crypto\Signature\SchnorrSignature;
use swentel\nostr\Key\Key;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\InteractiveAuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Authenticator for Nostr protocol-based authentication.
 *
 * This authenticator processes requests to the /login endpoint with a Nostr-based Authorization header.
 * It decodes and verifies the Nostr event, checks for expiration, and validates the Schnorr signature.
 * On successful authentication, it issues a SelfValidatingPassport with the user's public key in Bech32 format.
 *
 * Implements interactive authentication for Symfony security.
 */
class NostrAuthenticator extends AbstractAuthenticator implements InteractiveAuthenticatorInterface
{
    /**
     * Checks if the request should be handled by this authenticator.
     *
     * @param Request $request The HTTP request.
     * @return bool|null True if the request is supported, false otherwise.
     */
    public function supports(Request $request): ?bool
    {
        if ($request->getPathInfo() === '/login' && $request->headers->has('Authorization')) {
            return true;
        }
        return false;
    }

    /**
     * Performs authentication using the Nostr Authorization header.
     *
     * @param Request $request The HTTP request.
     * @return SelfValidatingPassport The authenticated passport.
     * @throws AuthenticationException If authentication fails (invalid header, expired, or invalid signature).
     */
    public function authenticate(Request $request): SelfValidatingPassport
    {
        $authHeader = $request->headers->get('Authorization');
        if (!str_starts_with($authHeader, 'Nostr ')) {
            throw new AuthenticationException('Invalid Authorization header');
        }

        $eventStr = base64_decode(substr($authHeader, 6), true);
        $encoders = [new JsonEncoder()];
        $normalizers = [new ObjectNormalizer()];
        $serializer = new Serializer($normalizers, $encoders);
        /** @var Event $event */
        $event = $serializer->deserialize($eventStr, Event::class, 'json');
        if (time() > $event->getCreatedAt() + 60) {
            throw new AuthenticationException('Expired');
        }
        $validity = (new SchnorrSignature())->verify($event->getPubkey(), $event->getSig(), $event->getId());
        if (!$validity) {
            throw new AuthenticationException('Invalid Authorization header');
        }

        $key = new Key();

        return new SelfValidatingPassport(
            new UserBadge($key->convertPublicKeyToBech32($event->getPubkey()))
        );
    }

    /**
     * Handles successful authentication.
     *
     * @param Request $request The HTTP request.
     * @param TokenInterface $token The authenticated token.
     * @param string $firewallName The firewall name.
     * @return Response|null The response to return, or null to continue.
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return new Response('Authentication Successful', 200);
    }

    /**
     * Handles failed authentication.
     *
     * @param Request $request The HTTP request.
     * @param AuthenticationException $exception The exception thrown during authentication.
     * @return Response|null The response to return, or null to continue.
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }

    /**
     * Indicates whether this authenticator is interactive.
     *
     * @return bool True if interactive.
     */
    public function isInteractive(): bool
    {
        return true;
    }
}
