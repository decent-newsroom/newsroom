<?php

namespace App\Security;

use App\Entity\Event;
use Mdanter\Ecc\Crypto\Signature\SchnorrSigner;
use swentel\nostr\Key\Key;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\InteractiveAuthenticatorInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;

/**
 * Authenticator for Nostr protocol-based authentication (NIP-98).
 *
 * This authenticator processes requests with a Nostr-based Authorization header.
 * It validates NIP-98 HTTP auth events (kind 27235) with proper URL and method verification.
 * Implements comprehensive security checks including expiration, signature validation, and event structure.
 */
class NostrAuthenticator extends AbstractAuthenticator implements InteractiveAuthenticatorInterface, AuthenticationEntryPointInterface
{
    private const NOSTR_AUTH_SCHEME = 'Nostr ';
    private const NIP98_KIND = 27235;
    private const MAX_EVENT_AGE_SECONDS = 60;

    /**
     * Checks if the request should be handled by this authenticator.
     */
    public function supports(Request $request): ?bool
    {
        // Only support requests with /login route
        $isLogin = $request->getPathInfo() === '/login';
        return $isLogin && $request->headers->has('Authorization') &&
               str_starts_with($request->headers->get('Authorization', ''), self::NOSTR_AUTH_SCHEME);
    }

    /**
     * Performs authentication using the Nostr Authorization header.
     */
    public function authenticate(Request $request): SelfValidatingPassport
    {
        try {
            $authHeader = $request->headers->get('Authorization');

            if (!str_starts_with($authHeader, self::NOSTR_AUTH_SCHEME)) {
                throw new AuthenticationException('Invalid Authorization scheme. Expected "Nostr" scheme.');
            }

            $eventData = $this->decodeAuthorizationHeader($authHeader);
            $event = $this->deserializeEvent($eventData);

            $this->validateEvent($event, $request);
            $this->validateSignature($event);

            return new SelfValidatingPassport(
                new UserBadge($this->convertToUserIdentifier($event->getPubkey())),
                [new RememberMeBadge()]
            );
        } catch (AuthenticationException $e) {
            // Re-throw authentication exceptions as-is
            throw $e;
        } catch (\Exception $e) {
            // Catch any other unexpected exceptions and convert them to authentication failures
            throw new AuthenticationException('Authentication failed due to invalid or malformed data: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // Catch even more severe errors (like ValueError from GMP operations)
            throw new AuthenticationException('Authentication failed due to cryptographic error: ' . $e->getMessage());
        }
    }

    /**
     * Decodes the base64-encoded event from the Authorization header.
     */
    private function decodeAuthorizationHeader(string $authHeader): string
    {
        try {
            $encodedEvent = substr($authHeader, strlen(self::NOSTR_AUTH_SCHEME));
            $decodedEvent = base64_decode($encodedEvent, true);

            if ($decodedEvent === false) {
                throw new AuthenticationException('Invalid base64 encoding in Authorization header.');
            }

            return $decodedEvent;
        } catch (\Throwable $e) {
            throw new AuthenticationException('Failed to decode authorization header: ' . $e->getMessage());
        }
    }

    /**
     * Deserializes the JSON event data into an Event object.
     */
    private function deserializeEvent(string $eventData): Event
    {
        try {
            $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);

            /** @var Event $event */
            $event = $serializer->deserialize($eventData, Event::class, 'json');
            return $event;
        } catch (NotEncodableValueException $e) {
            throw new AuthenticationException('Invalid JSON in authorization event: ' . $e->getMessage());
        } catch (\Throwable $e) {
            throw new AuthenticationException('Failed to parse event data: ' . $e->getMessage());
        }
    }

    /**
     * Validates the Nostr event according to NIP-98 specifications.
     */
    private function validateEvent(Event $event, Request $request): void
    {
        try {
            // Validate event kind (must be 27235 for HTTP auth)
            if ($event->getKind() !== self::NIP98_KIND) {
                throw new AuthenticationException('Invalid event kind. Expected ' . self::NIP98_KIND . ' for HTTP authentication.');
            }

            // Validate timestamp (not expired)
            if (time() > $event->getCreatedAt() + self::MAX_EVENT_AGE_SECONDS) {
                throw new AuthenticationException('Authentication event has expired.');
            }

            // Validate required fields
            if (empty($event->getPubkey()) || empty($event->getSig()) || empty($event->getId())) {
                throw new AuthenticationException('Missing required event fields (pubkey, sig, or id).');
            }

            // Validate NIP-98 tags (URL and method)
            $this->validateNip98Tags($event, $request);
        } catch (AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AuthenticationException('Event validation failed: ' . $e->getMessage());
        }
    }

    /**
     * Validates NIP-98 specific tags (URL and HTTP method).
     */
    private function validateNip98Tags(Event $event, Request $request): void
    {
        try {
            $tags = $event->getTags();
            $foundUrl = false;
            $foundMethod = false;

            foreach ($tags as $tag) {
                if (count($tag) >= 2) {
                    if ($tag[0] === 'u') {
                        $foundUrl = true;
                        $expectedUrl = $request->getSchemeAndHttpHost() . $request->getRequestUri();
                        if ($tag[1] !== $expectedUrl) {
                            throw new AuthenticationException('URL tag does not match request URL.');
                        }
                    }
                    if ($tag[0] === 'method') {
                        $foundMethod = true;
                        if ($tag[1] !== $request->getMethod()) {
                            throw new AuthenticationException('Method tag does not match request method.');
                        }
                    }
                }
            }

            if (!$foundUrl) {
                throw new AuthenticationException('Missing required "u" (URL) tag in authentication event.');
            }

            if (!$foundMethod) {
                throw new AuthenticationException('Missing required "method" tag in authentication event.');
            }
        } catch (AuthenticationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AuthenticationException('Tag validation failed: ' . $e->getMessage());
        }

        // Detect bunker vs extension login by presence of a client tag
        try {
            $tags = $event->getTags();
            if (is_array($tags)) {
                foreach ($tags as $tag) {
                    if (is_array($tag) && isset($tag[0], $tag[1]) && $tag[0] === 't') {
                        $method = $tag[1];
                        if (in_array($method, ['bunker', 'extension'], true)) {
                            $request->getSession()->set('nostr_sign_method', $method);
                        }
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            // non-fatal
        }
    }

    /**
     * Validates the Schnorr signature of the event.
     */
    private function validateSignature(Event $event): void
    {
        try {
            $schnorr = new SchnorrSigner();
            $isValid = $schnorr->verify($event->getPubkey(), $event->getSig(), $event->getId());

            if (!$isValid) {
                throw new AuthenticationException('Invalid event signature.');
            }
        } catch (AuthenticationException $e) {
            throw $e;
        } catch (\ValueError $e) {
            // Handle GMP errors specifically (like gmp_init errors with invalid hex strings)
            throw new AuthenticationException('Invalid signature format or public key format.');
        } catch (\Exception $e) {
            throw new AuthenticationException('Signature verification failed: ' . $e->getMessage());
        } catch (\Throwable $e) {
            // Catch any other errors (like memory issues, etc.)
            throw new AuthenticationException('Cryptographic verification failed due to system error.');
        }
    }

    /**
     * Converts the public key to a user identifier (Bech32 format).
     */
    private function convertToUserIdentifier(string $pubkey): string
    {
        try {
            $key = new Key();
            return $key->convertPublicKeyToBech32($pubkey);
        } catch (\Throwable $e) {
            throw new AuthenticationException('Failed to convert public key to user identifier: ' . $e->getMessage());
        }
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Return null to continue to the intended route
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new Response(
            json_encode(['error' => 'Authentication failed', 'message' => $exception->getMessage()]),
            Response::HTTP_UNAUTHORIZED,
            ['Content-Type' => 'application/json']
        );
    }

    public function start(Request $request, AuthenticationException $authException = null): Response
    {
        $message = 'Authentication required';
        if ($authException) {
            $message = $authException->getMessage();
        }

        // Check if request expects JSON (API requests)
        $acceptHeader = $request->headers->get('Accept', '');
        $contentType = $request->headers->get('Content-Type', '');
        $isJsonRequest = str_contains($acceptHeader, 'application/json') ||
                         str_contains($contentType, 'application/json') ||
                         $request->isXmlHttpRequest();

        if ($isJsonRequest) {
            return new Response(
                json_encode(['error' => 'Authentication required', 'message' => $message]),
                Response::HTTP_UNAUTHORIZED,
                ['Content-Type' => 'application/json']
            );
        }

        // For HTML requests, redirect to login page
        return new Response(
            '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Authentication Required</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 50px; }
        .error-box { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 20px; border-radius: 5px; }
        a { color: #004085; }
    </style>
</head>
<body>
    <div class="error-box">
        <h1>Authentication Required</h1>
        <p>' . htmlspecialchars($message) . '</p>
        <p><a href="/login">Please log in to continue</a></p>
    </div>
</body>
</html>',
            Response::HTTP_UNAUTHORIZED,
            ['Content-Type' => 'text/html']
        );
    }

    public function isInteractive(): bool
    {
        return true;
    }
}
