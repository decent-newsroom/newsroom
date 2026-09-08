<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use nostriphant\NIP19\Bech32;

/**
 * Application-facing NIP-19 encoder/decoder.
 */
final class NostrNip19Service
{
    /** @return array<string, mixed> */
    public function decode(string $identifier): array
    {
        $bech32 = new Bech32(strtolower(trim($identifier)));
        $data = $bech32();
        if (is_string($data)) {
            return [$bech32->type === 'npub' ? 'pubkey' : 'event_id' => $data];
        }

        $result = [];
        foreach (['id' => 'event_id', 'pubkey' => 'pubkey', 'identifier' => 'identifier', 'kind' => 'kind', 'relays' => 'relays'] as $property => $key) {
            if (isset($data->{$property})) {
                $result[$key] = $data->{$property};
            }
        }

        return $result;
    }

    public function encodeNote(string $eventId): string
    {
        return (string) Bech32::note(strtolower(trim($eventId)));
    }

    public function encodeNpub(string $pubkey): string
    {
        return (string) Bech32::npub(strtolower(trim($pubkey)));
    }

    /**
     * @param list<string> $relays
     */
    public function encodeAddr(string $pubkey, string $identifier, int $kind, array $relays = []): string
    {
        return (string) Bech32::naddr(
            pubkey: strtolower(trim($pubkey)),
            relays: $relays,
            kind: $kind,
            identifier: $identifier,
        );
    }
}
