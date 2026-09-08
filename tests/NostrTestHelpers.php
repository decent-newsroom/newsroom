<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\Nostr\NostrSigner;
use Innis\Nostr\Core\Domain\Entity\Event;

/**
 * Helper trait providing common utilities for Nostr authentication testing.
 */
trait NostrTestHelpers
{
    private ?string $testPrivateKey = null;

    protected function setUpNostrHelpers(): void
    {
        $this->testPrivateKey = bin2hex(random_bytes(32));
    }

    protected function createValidToken(string $method, string $url): string
    {
        return $this->createToken(27235, $method, $url);
    }

    protected function createTokenWithTimestamp(string $method, string $url, int $timestamp): string
    {
        return $this->createToken(27235, $method, $url, $timestamp);
    }

    protected function createTokenWithInvalidSignature(string $method, string $url): string
    {
        $eventData = $this->signedEvent(27235, $method, $url)->toArray();
        $eventData['sig'] = 'invalid_signature_'.substr((string) $eventData['sig'], 0, 50);

        return $this->encodeToken($eventData);
    }

    protected function createTokenWithEmptySignature(string $method, string $url): string
    {
        $eventData = $this->signedEvent(27235, $method, $url)->toArray();
        $eventData['sig'] = '';

        return $this->encodeToken($eventData);
    }

    protected function createTokenWithMalformedSignature(string $method, string $url): string
    {
        $eventData = $this->signedEvent(27235, $method, $url)->toArray();
        $eventData['sig'] = 'not_hex_signature!@#$%';

        return $this->encodeToken($eventData);
    }

    protected function createTokenWithInvalidPubkey(string $method, string $url): string
    {
        $eventData = $this->signedEvent(27235, $method, $url)->toArray();
        $eventData['pubkey'] = 'invalid_pubkey_'.substr((string) $eventData['pubkey'], 0, 50);

        return $this->encodeToken($eventData);
    }

    protected function createTokenWithEmptyPubkey(string $method, string $url): string
    {
        $eventData = $this->signedEvent(27235, $method, $url)->toArray();
        $eventData['pubkey'] = '';

        return $this->encodeToken($eventData);
    }

    protected function createTokenWithMalformedPubkey(string $method, string $url): string
    {
        $eventData = $this->signedEvent(27235, $method, $url)->toArray();
        $eventData['pubkey'] = 'not_hex_pubkey!@#$%';

        return $this->encodeToken($eventData);
    }

    protected function createTokenWithKind(int $kind, string $method, string $url): string
    {
        return $this->createToken($kind, $method, $url);
    }

    protected function createTokenWithoutTag(string $tagToRemove, string $method, string $url): string
    {
        $tags = [];
        if ($tagToRemove !== 'u') {
            $tags[] = ['u', $url];
        }
        if ($tagToRemove !== 'method') {
            $tags[] = ['method', $method];
        }

        return $this->encodeToken($this->signedEvent(27235, $method, $url, null, $tags)->toArray());
    }

    protected function createTokenWithPayloadHash(string $method, string $url, string $payload): string
    {
        $hash = hash('sha256', $payload);

        return $this->encodeToken($this->signedEvent(
            27235,
            $method,
            $url,
            null,
            [['u', $url], ['method', $method], ['payload', $hash]],
            $hash,
        )->toArray());
    }

    /** @param list<list<string>>|null $tags */
    private function signedEvent(
        int $kind,
        string $method,
        string $url,
        ?int $createdAt = null,
        ?array $tags = null,
        string $content = '',
    ): Event {
        /** @var NostrSigner $signer */
        $signer = static::getContainer()->get(NostrSigner::class);

        return $signer->signWithPrivateKey(
            $kind,
            $tags ?? [['u', $url], ['method', $method]],
            $content,
            $this->testPrivateKey ?? throw new \LogicException('Nostr helpers are not initialized.'),
            $createdAt ?? time(),
        );
    }

    private function createToken(int $kind, string $method, string $url, ?int $createdAt = null): string
    {
        return $this->encodeToken($this->signedEvent($kind, $method, $url, $createdAt)->toArray());
    }

    /** @param array<string, mixed> $eventData */
    private function encodeToken(array $eventData): string
    {
        return 'Nostr '.base64_encode(json_encode($eventData, JSON_THROW_ON_ERROR));
    }
}
