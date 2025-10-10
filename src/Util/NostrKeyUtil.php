<?php

declare(strict_types=1);

namespace App\Util;

use swentel\nostr\Key\Key;

class NostrKeyUtil
{
    /**
     * Check if a string is a valid hex pubkey (64 hex chars).
     */
    public static function isHexPubkey(string $pubkey): bool
    {
        return (bool) preg_match('/^[a-fA-F0-9]{64}$/', $pubkey);
    }

    /**
     * Check if a string is a valid bech32 npub (starts with npub1).
     */
    public static function isNpub(string $npub): bool
    {
        return str_starts_with($npub, 'npub1');
    }

    /**
     * Convert npub (bech32) to hex pubkey.
     * Throws \InvalidArgumentException if invalid.
     */
    public static function npubToHex(string $npub): string
    {
        if (!self::isNpub($npub)) {
            throw new \InvalidArgumentException('Not a valid npub');
        }
        $key = new Key();
        return $key->convertToHex($npub);
    }

    /**
     * Convert hex pubkey to npub (bech32).
     * Throws \InvalidArgumentException if invalid.
     */
    public static function hexToNpub(string $pubkey): string
    {
        if (!self::isHexPubkey($pubkey)) {
            throw new \InvalidArgumentException('Not a valid hex pubkey');
        }
        $key = new Key();
        return $key->convertPublicKeyToBech32($pubkey);
    }
}

