<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Nostr;

use App\Service\Nostr\NostrKeyService;
use App\Service\Nostr\NostrNip19Service;
use PHPUnit\Framework\TestCase;

final class NostrIdentityAdaptersTest extends TestCase
{
    public function testPublicKeyAdapterRoundTripsNpub(): void
    {
        $hex = str_repeat('a', 64);
        $service = new NostrKeyService();

        $npub = $service->convertPublicKeyToBech32($hex);

        self::assertSame($hex, $service->convertToHex($npub));
    }

    public function testNip19AdapterRoundTripsNoteAndAddress(): void
    {
        $hex = str_repeat('a', 64);
        $service = new NostrNip19Service();

        $note = $service->encodeNote($hex);
        self::assertSame(['event_id' => $hex], $service->decode($note));

        $address = $service->encodeAddr($hex, 'article', 30023, ['wss://relay.example']);
        $decoded = $service->decode($address);
        self::assertSame('article', $decoded['identifier']);
        self::assertSame($hex, $decoded['pubkey']);
        self::assertSame(30023, $decoded['kind']);
        self::assertSame(['wss://relay.example'], $decoded['relays']);
    }
}
