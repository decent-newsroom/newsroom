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

    /**
     * Check if a string is a valid Nostr identifier (bech32 encoded).
     * Returns the type if valid, null otherwise.
     * Supported types: npub, naddr, nevent, note, nprofile, nsec
     */
    public static function getNostrIdentifierType(string $identifier): ?string
    {
        $identifier = trim($identifier);

        // Remove nostr: prefix if present
        if (str_starts_with($identifier, 'nostr:')) {
            $identifier = substr($identifier, 6);
        }

        $validPrefixes = ['npub1', 'naddr1', 'nevent1', 'note1', 'nprofile1', 'nsec1'];

        foreach ($validPrefixes as $prefix) {
            if (str_starts_with($identifier, $prefix)) {
                return rtrim($prefix, '1'); // Return type without the '1'
            }
        }

        return null;
    }

    /**
     * Normalize a Nostr identifier by removing the nostr: prefix if present.
     */
    public static function normalizeNostrIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        if (str_starts_with($identifier, 'nostr:')) {
            return substr($identifier, 6);
        }

        return $identifier;
    }
}

