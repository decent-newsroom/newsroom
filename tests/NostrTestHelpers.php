<?php

declare(strict_types=1);

namespace App\Tests;

use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;
use swentel\nostr\Sign\Sign;

/**
 * Helper trait providing common utilities for Nostr authentication testing.
 */
trait NostrTestHelpers
{
    private ?Key $testKey = null;
    private ?string $testPrivateKey = null;

    protected function setUpNostrHelpers(): void
    {
        $this->testKey = new Key();
        $this->testPrivateKey = $this->testKey->generatePrivateKey();
    }

    protected function createValidToken(string $method, string $url): string
    {
        $event = new Event();
        $event->setContent('');
        $event->setKind(27235);
        $event->setCreatedAt(time());
        $event->setTags([
            ["u", $url],
            ["method", $method]
        ]);

        $signer = new Sign();
        $signer->signEvent($event, $this->testPrivateKey);

        return 'Nostr ' . base64_encode($event->toJson());
    }

    protected function createTokenWithTimestamp(string $method, string $url, int $timestamp): string
    {
        $event = new Event();
        $event->setContent('');
        $event->setKind(27235);
        $event->setCreatedAt($timestamp);
        $event->setTags([
            ["u", $url],
            ["method", $method]
        ]);

        $signer = new Sign();
        $signer->signEvent($event, $this->testPrivateKey);

        return 'Nostr ' . base64_encode($event->toJson());
    }

    protected function createTokenWithInvalidSignature(string $method, string $url): string
    {
        $event = new Event();
        $event->setContent('');
        $event->setKind(27235);
        $event->setCreatedAt(time());
        $event->setTags([
            ["u", $url],
            ["method", $method]
        ]);

        $signer = new Sign();
        $signer->signEvent($event, $this->testPrivateKey);

        // Corrupt the signature
        $eventData = json_decode($event->toJson(), true);
        $eventData['sig'] = 'invalid_signature_' . substr($eventData['sig'], 0, 50);

        return 'Nostr ' . base64_encode(json_encode($eventData));
    }

    protected function createTokenWithEmptySignature(string $method, string $url): string
    {
        $event = new Event();
        $event->setContent('');
        $event->setKind(27235);
        $event->setCreatedAt(time());
        $event->setTags([
            ["u", $url],
            ["method", $method]
        ]);

        $signer = new Sign();
        $signer->signEvent($event, $this->testPrivateKey);

        // Empty the signature
        $eventData = json_decode($event->toJson(), true);
        $eventData['sig'] = '';

        return 'Nostr ' . base64_encode(json_encode($eventData));
    }

    protected function createTokenWithMalformedSignature(string $method, string $url): string
    {
        $event = new Event();
        $event->setContent('');
        $event->setKind(27235);
        $event->setCreatedAt(time());
        $event->setTags([
            ["u", $url],
            ["method", $method]
        ]);

        $signer = new Sign();
        $signer->signEvent($event, $this->testPrivateKey);

        // Malform the signature
        $eventData = json_decode($event->toJson(), true);
        $eventData['sig'] = 'not_hex_signature!@#$%';

        return 'Nostr ' . base64_encode(json_encode($eventData));
    }

    protected function createTokenWithInvalidPubkey(string $method, string $url): string
    {
        $event = new Event();
        $event->setContent('');
        $event->setKind(27235);
        $event->setCreatedAt(time());
        $event->setTags([
            ["u", $url],
            ["method", $method]
        ]);

        $signer = new Sign();
        $signer->signEvent($event, $this->testPrivateKey);

        // Corrupt the pubkey
        $eventData = json_decode($event->toJson(), true);
        $eventData['pubkey'] = 'invalid_pubkey_' . substr($eventData['pubkey'], 0, 50);

        return 'Nostr ' . base64_encode(json_encode($eventData));
    }

    protected function createTokenWithEmptyPubkey(string $method, string $url): string
    {
        $event = new Event();
        $event->setContent('');
        $event->setKind(27235);
        $event->setCreatedAt(time());
        $event->setTags([
            ["u", $url],
            ["method", $method]
        ]);

        $signer = new Sign();
        $signer->signEvent($event, $this->testPrivateKey);

        // Empty the pubkey
        $eventData = json_decode($event->toJson(), true);
        $eventData['pubkey'] = '';

        return 'Nostr ' . base64_encode(json_encode($eventData));
    }

    protected function createTokenWithMalformedPubkey(string $method, string $url): string
    {
        $event = new Event();
        $event->setContent('');
        $event->setKind(27235);
        $event->setCreatedAt(time());
        $event->setTags([
            ["u", $url],
            ["method", $method]
        ]);

        $signer = new Sign();
        $signer->signEvent($event, $this->testPrivateKey);

        // Malform the pubkey
        $eventData = json_decode($event->toJson(), true);
        $eventData['pubkey'] = 'not_hex_pubkey!@#$%';

        return 'Nostr ' . base64_encode(json_encode($eventData));
    }

    protected function createTokenWithKind(int $kind, string $method, string $url): string
    {
        $event = new Event();
        $event->setContent('');
        $event->setKind($kind);
        $event->setCreatedAt(time());
        $event->setTags([
            ["u", $url],
            ["method", $method]
        ]);

        $signer = new Sign();
        $signer->signEvent($event, $this->testPrivateKey);

        return 'Nostr ' . base64_encode($event->toJson());
    }

    protected function createTokenWithoutTag(string $tagToRemove, string $method, string $url): string
    {
        $event = new Event();
        $event->setContent('');
        $event->setKind(27235);
        $event->setCreatedAt(time());

        $tags = [];
        if ($tagToRemove !== 'u') {
            $tags[] = ["u", $url];
        }
        if ($tagToRemove !== 'method') {
            $tags[] = ["method", $method];
        }

        $event->setTags($tags);

        $signer = new Sign();
        $signer->signEvent($event, $this->testPrivateKey);

        return 'Nostr ' . base64_encode($event->toJson());
    }

    protected function createTokenWithPayloadHash(string $method, string $url, string $payload): string
    {
        $event = new Event();
        $event->setContent(hash('sha256', $payload));
        $event->setKind(27235);
        $event->setCreatedAt(time());
        $event->setTags([
            ["u", $url],
            ["method", $method],
            ["payload", hash('sha256', $payload)]
        ]);

        $signer = new Sign();
        $signer->signEvent($event, $this->testPrivateKey);

        return 'Nostr ' . base64_encode($event->toJson());
    }
}
