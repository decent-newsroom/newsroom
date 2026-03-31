<?php

declare(strict_types=1);

namespace App\Util;

/**
 * Single source of truth for relay URL normalization.
 *
 * Relay URLs appear in many places: the registry, health store, gateway
 * connection keys, mute sets, relay lists from NIP-65 events, etc.
 * Previously each consumer had its own inline normalization — some
 * lowercased, some didn't, some trimmed, some didn't. This caused
 * subtle mismatches: a relay recorded under one key in the health store
 * could be looked up under a different key by the gateway.
 *
 * This class provides one canonical normalize() that every consumer
 * should use. The rules are:
 *   1. Trim whitespace
 *   2. Lowercase (RFC 3986: scheme and host are case-insensitive)
 *   3. Strip trailing slash
 *
 * @see documentation/Nostr/relay-management-review.md §1
 */
final class RelayUrlNormalizer
{
    /**
     * Normalize a relay URL for consistent comparison and storage.
     *
     * Safe to call on any string — never throws, always returns a string.
     */
    public static function normalize(string $url): string
    {
        return rtrim(strtolower(trim($url)), '/');
    }

    /**
     * Check if two relay URLs refer to the same endpoint.
     */
    public static function equals(string $a, string $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }
}

