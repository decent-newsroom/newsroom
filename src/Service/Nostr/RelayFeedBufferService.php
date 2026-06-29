<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Util\RelayUrlNormalizer;

/**
 * Redis-backed rolling buffer for relay feed events.
 *
 * Each relay identified by a deterministic 16-char hex key maps to:
 *   - relay_feed:url:{key}    — the canonical relay URL (TTL 1 h)
 *   - relay_feed:buffer:{key} — LPUSH list of JSON-encoded raw cards, trimmed to 100 (TTL 1 h)
 *   - relay_feed:seen:{key}   — SET of already-seen event IDs for dedup (TTL 1 h)
 *   - relay_feed:active:{key} — presence flag renewed by page views / keepalives (TTL 10 min)
 */
class RelayFeedBufferService
{
    private const BUFFER_KEY = 'relay_feed:buffer:%s';
    private const SEEN_KEY   = 'relay_feed:seen:%s';
    private const URL_KEY    = 'relay_feed:url:%s';
    private const ACTIVE_KEY = 'relay_feed:active:%s';

    public const BUFFER_MAX  = 100;
    public const BUFFER_TTL  = 3600;  // 1 hour
    public const ACTIVE_TTL  = 600;   // 10 minutes

    public function __construct(
        private readonly \Redis $redis,
    ) {}

    /**
     * Derive a stable, URL-safe 16-char hex key from a relay URL.
     */
    public function makeKey(string $relayUrl): string
    {
        return substr(sha1(RelayUrlNormalizer::normalize($relayUrl)), 0, 16);
    }

    public function storeRelayUrl(string $key, string $url): void
    {
        $this->redis->setex(sprintf(self::URL_KEY, $key), self::BUFFER_TTL, $url);
    }

    public function getRelayUrl(string $key): ?string
    {
        $value = $this->redis->get(sprintf(self::URL_KEY, $key));
        return ($value !== false && $value !== null) ? (string) $value : null;
    }

    /**
     * Mark the feed as actively wanted (renewed by page loads and keepalive pings).
     */
    public function markActive(string $key): void
    {
        $this->redis->setex(sprintf(self::ACTIVE_KEY, $key), self::ACTIVE_TTL, '1');
    }

    public function isActive(string $key): bool
    {
        return (bool) $this->redis->exists(sprintf(self::ACTIVE_KEY, $key));
    }

    public function alreadySeen(string $key, string $eventId): bool
    {
        return (bool) $this->redis->sIsMember(sprintf(self::SEEN_KEY, $key), $eventId);
    }

    public function markSeen(string $key, string $eventId): void
    {
        $seenKey = sprintf(self::SEEN_KEY, $key);
        $this->redis->sAdd($seenKey, $eventId);
        $this->redis->expire($seenKey, self::BUFFER_TTL);
    }

    /**
     * Push a raw article card to the head of the rolling buffer.
     * Trims the list to BUFFER_MAX entries (newest first).
     *
     * @param array{id:string, pubkey:string, npub:string, created_at:int, title:string, summary:string, image:string, d_tag:string, naddr:string, relay:string} $card
     */
    public function pushToBuffer(string $key, array $card): void
    {
        $bufKey = sprintf(self::BUFFER_KEY, $key);
        $this->redis->lPush($bufKey, json_encode($card));
        $this->redis->lTrim($bufKey, 0, self::BUFFER_MAX - 1);
        $this->redis->expire($bufKey, self::BUFFER_TTL);
    }

    /**
     * Return the buffered cards for a relay feed (newest first, up to BUFFER_MAX).
     *
     * @return array<array<string, mixed>>
     */
    public function getBuffer(string $key): array
    {
        $bufKey = sprintf(self::BUFFER_KEY, $key);
        $items  = $this->redis->lRange($bufKey, 0, -1);

        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn(string $item) => json_decode($item, true), $items),
            fn($v) => is_array($v)
        ));
    }
}
