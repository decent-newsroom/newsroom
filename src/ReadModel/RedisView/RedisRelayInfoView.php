<?php

declare(strict_types=1);

namespace App\ReadModel\RedisView;

use App\Util\RelayUrlNormalizer;
use Psr\Log\LoggerInterface;

/**
 * Redis-backed read cache for NIP-11 documents.
 *
 * Used by hot ingestion paths (the relay gateway preflight check) so that
 * deciding "should we even open this WebSocket?" never reaches Postgres.
 * Authoritative storage lives in {@see \App\Entity\RelayInformation}.
 *
 * Key: `relay_info:{normalized-url}`. Values are stored as JSON.
 */
class RedisRelayInfoView
{
    public const KEY_PREFIX = 'relay_info:';
    public const DEFAULT_TTL = 21600; // 6 hours, aligned with refresh cron

    public function __construct(
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $url): ?array
    {
        try {
            $raw = $this->redis->get($this->key($url));
            if (!is_string($raw) || $raw === '') {
                return null;
            }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        } catch (\RedisException $e) {
            $this->logger->debug('RedisRelayInfoView: read failed', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function set(string $url, array $payload, ?int $ttl = null): void
    {
        try {
            $this->redis->setex(
                $this->key($url),
                $ttl ?? self::DEFAULT_TTL,
                json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}'
            );
        } catch (\RedisException $e) {
            $this->logger->debug('RedisRelayInfoView: write failed', ['url' => $url, 'error' => $e->getMessage()]);
        }
    }

    public function forget(string $url): void
    {
        try {
            $this->redis->del($this->key($url));
        } catch (\RedisException) {}
    }

    /**
     * Lightweight "is auth required" check for the gateway preflight. Returns
     * null when the relay is unknown — caller should fall through to the
     * normal connect path.
     */
    public function isAuthRequired(string $url): ?bool
    {
        $payload = $this->get($url);
        if ($payload === null) {
            return null;
        }
        return (bool) ($payload['auth_required'] ?? false);
    }

    private function key(string $url): string
    {
        return self::KEY_PREFIX . RelayUrlNormalizer::normalize($url);
    }
}

