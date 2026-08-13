<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Security\IdentityProvider;

use DecentNewsroom\IdentityBundle\Contract\IdentityProviderInterface;
use DecentNewsroom\NostrKernelBundle\Application\Auth\ValidateNostrHttpAuth;
use DecentNewsroom\NostrKernelBundle\Domain\Auth\NostrHttpAuthToken;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventId;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventKind;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventSignature;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventTags;
use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;
use DecentNewsroom\NostrKernelBundle\Domain\Identity\Pubkey;
use DecentNewsroom\NostrKernelBundle\Exception\InvalidEventId;
use DecentNewsroom\NostrKernelBundle\Exception\InvalidPubkey;
use DecentNewsroom\NostrKernelBundle\Exception\InvalidSignature;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

final readonly class NostrIdentityProvider implements IdentityProviderInterface
{
    private const NOSTR_AUTH_SCHEME = 'Nostr ';
    private const MAX_EVENT_AGE_SECONDS = 60;

    public function __construct(private ValidateNostrHttpAuth $validateNostrHttpAuth)
    {
    }

    public function getName(): string
    {
        return 'nostr';
    }

    public function supports(Request $request): bool
    {
        return $request->getPathInfo() === '/login'
            && \str_starts_with($request->headers->get('Authorization', ''), self::NOSTR_AUTH_SCHEME);
    }

    public function authenticate(Request $request): string
    {
        $event = $this->eventFromAuthorizationHeader($request->headers->get('Authorization', ''));
        $result = ($this->validateNostrHttpAuth)(new NostrHttpAuthToken(
            $event,
            $request->getMethod(),
            $request->getSchemeAndHttpHost() . $request->getRequestUri(),
            self::MAX_EVENT_AGE_SECONDS,
        ));

        if (!$result->isValid()) {
            throw new AuthenticationException(\implode(' ', $result->errors()));
        }

        $this->rememberSignMethod($request, $event);

        return $result->pubkey()?->toHex()
            ?? throw new AuthenticationException('Authentication succeeded without a public key.');
    }

    private function eventFromAuthorizationHeader(string $authHeader): NostrEvent
    {
        if (!\str_starts_with($authHeader, self::NOSTR_AUTH_SCHEME)) {
            throw new AuthenticationException('Invalid Authorization scheme. Expected "Nostr" scheme.');
        }

        $decodedEvent = \base64_decode(\substr($authHeader, \strlen(self::NOSTR_AUTH_SCHEME)), true);
        if ($decodedEvent === false) {
            throw new AuthenticationException('Invalid base64 encoding in Authorization header.');
        }

        try {
            $eventData = \json_decode($decodedEvent, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new AuthenticationException('Invalid JSON in authorization event: ' . $e->getMessage(), previous: $e);
        }

        if (!\is_array($eventData) || \array_is_list($eventData)) {
            throw new AuthenticationException('Invalid JSON in authorization event: expected an event object.');
        }

        return $this->eventFromArray($eventData);
    }

    /**
     * @param array<string,mixed> $eventData
     */
    private function eventFromArray(array $eventData): NostrEvent
    {
        if (!isset($eventData['kind']) || !\is_int($eventData['kind'])) {
            throw new AuthenticationException('Invalid event kind. Expected 27235 for HTTP authentication.');
        }

        if (
            !isset($eventData['pubkey'], $eventData['sig'], $eventData['id'])
            || !\is_string($eventData['pubkey'])
            || !\is_string($eventData['sig'])
            || !\is_string($eventData['id'])
            || $eventData['pubkey'] === ''
            || $eventData['sig'] === ''
            || $eventData['id'] === ''
        ) {
            throw new AuthenticationException('Missing required event fields (pubkey, sig, or id).');
        }

        if (!isset($eventData['created_at']) || !\is_int($eventData['created_at'])) {
            throw new AuthenticationException('Authentication event has expired or has an invalid timestamp.');
        }

        if (!isset($eventData['tags']) || !\is_array($eventData['tags'])) {
            throw new AuthenticationException('Invalid authentication event tags.');
        }

        $content = $eventData['content'] ?? '';
        if (!\is_string($content)) {
            $encoded = \json_encode($content, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_LINE_TERMINATORS);
            if ($encoded === false) {
                throw new AuthenticationException('Invalid authentication event content.');
            }
            $content = $encoded;
        }

        try {
            return new NostrEvent(
                kind: new EventKind($eventData['kind']),
                pubkey: new Pubkey(\strtolower($eventData['pubkey'])),
                tags: EventTags::fromRaw($eventData['tags']),
                content: $content,
                createdAt: $eventData['created_at'],
                id: new EventId(\strtolower($eventData['id'])),
                signature: new EventSignature(\strtolower($eventData['sig'])),
            );
        } catch (InvalidPubkey $e) {
            throw new AuthenticationException('Failed to convert public key to user identifier: ' . $e->getMessage(), previous: $e);
        } catch (InvalidEventId|InvalidSignature $e) {
            throw new AuthenticationException('Invalid signature format or public key format.', previous: $e);
        } catch (\Throwable $e) {
            throw new AuthenticationException('Authentication failed due to invalid or malformed data: ' . $e->getMessage(), previous: $e);
        }
    }

    private function rememberSignMethod(Request $request, NostrEvent $event): void
    {
        $method = $event->tags()->firstValue('t');
        if (!\in_array($method, ['bunker', 'extension'], true)) {
            return;
        }

        if (!$request->hasSession()) {
            return;
        }

        $request->getSession()->set('nostr_sign_method', $method);
    }
}
