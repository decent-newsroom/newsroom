<?php

namespace App\Service\Nostr;

use Psr\Log\LoggerInterface;

/**
 * Per-user relay activity log.
 *
 * Stores a small Redis Stream per logged-in pubkey capturing the operations
 * the relay gateway performs on their behalf — AUTH challenges signed,
 * publishes accepted/rejected. Surfaced to the user on the Settings → Relays
 * tab so they can see why a publish failed or whether AUTH succeeded
 * silently in the background.
 *
 * Why a Stream and not a List:
 *   - `XADD MAXLEN ~ N` gives us bounded, FIFO storage with O(1) writes.
 *   - Trivial to read newest-first via `XREVRANGE`.
 *   - The whole key carries an EXPIRE so dormant users don't pile up.
 *
 * This is intentionally not a privacy boundary: writes happen inside the
 * gateway process which already has the pubkey, and reads are gated by the
 * authenticated session matching that pubkey at the controller layer.
 */
class RelayUserActivityStore
{
    private const KEY_PREFIX = 'relay_user_activity:';
    private const MAX_ENTRIES = 200;        // ~recent week of activity for an active user
    private const KEY_TTL_SECONDS = 604800; // 7 days

    public const TYPE_AUTH    = 'auth';
    public const TYPE_PUBLISH = 'publish';

    public const STATUS_OK      = 'ok';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_PENDING = 'pending';

    /** @var string[] */
    public const AUTH_METHODS = ['nip07', 'nip46', 'none'];

    public function __construct(
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Record an AUTH event for a user-keyed connection.
     *
     * @param string $method One of self::AUTH_METHODS. 'none' indicates the
     *                       relay accepted the connection without an AUTH
     *                       challenge (settled).
     * @param string $status One of self::STATUS_*.
     */
    public function recordAuth(
        string $pubkeyHex,
        string $relayUrl,
        string $method,
        string $status,
        ?string $detail = null,
    ): void {
        $this->append($pubkeyHex, [
            'type'   => self::TYPE_AUTH,
            'relay'  => $relayUrl,
            'method' => $method,
            'status' => $status,
            'detail' => $detail ?? '',
        ]);
    }

    /**
     * Record the outcome of a publish to one relay.
     */
    public function recordPublish(
        string $pubkeyHex,
        string $relayUrl,
        ?string $eventId,
        bool $accepted,
        ?string $detail = null,
    ): void {
        $this->append($pubkeyHex, [
            'type'     => self::TYPE_PUBLISH,
            'relay'    => $relayUrl,
            'event_id' => $eventId ?? '',
            'status'   => $accepted ? self::STATUS_OK : self::STATUS_FAILED,
            'detail'   => $detail ?? '',
        ]);
    }

    /**
     * Read the most recent activity entries for a pubkey, newest first.
     *
     * @return array<int, array{
     *     id: string,
     *     ts: int,
     *     type: string,
     *     relay: string,
     *     status: string,
     *     method?: string,
     *     event_id?: string,
     *     detail?: string,
     * }>
     */
    public function getRecent(string $pubkeyHex, int $limit = 50): array
    {
        if (!$this->isHex64($pubkeyHex)) {
            return [];
        }
        $limit = max(1, min($limit, self::MAX_ENTRIES));

        try {
            // XREVRANGE returns newest first. Map: id => ['k1' => 'v1', ...]
            $raw = $this->redis->xRevRange($this->key($pubkeyHex), '+', '-', $limit);
        } catch (\RedisException $e) {
            $this->logger->debug('RelayUserActivityStore: read failed', [
                'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                'error'  => $e->getMessage(),
            ]);
            return [];
        }

        if (!is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $id => $fields) {
            // Stream IDs are "<ms>-<seq>"; the ms part is our timestamp.
            $tsMs = (int) explode('-', (string) $id, 2)[0];
            $entry = [
                'id'    => (string) $id,
                'ts'    => intdiv($tsMs, 1000),
                'type'  => $fields['type'] ?? '',
                'relay' => $fields['relay'] ?? '',
                'status' => $fields['status'] ?? '',
            ];
            if (isset($fields['method'])) {
                $entry['method'] = $fields['method'];
            }
            if (isset($fields['event_id']) && $fields['event_id'] !== '') {
                $entry['event_id'] = $fields['event_id'];
            }
            if (isset($fields['detail']) && $fields['detail'] !== '') {
                $entry['detail'] = $fields['detail'];
            }
            $result[] = $entry;
        }
        return $result;
    }

    /**
     * Drop the entire activity log for a user. Called on logout.
     */
    public function clear(string $pubkeyHex): void
    {
        if (!$this->isHex64($pubkeyHex)) {
            return;
        }
        try {
            $this->redis->del($this->key($pubkeyHex));
        } catch (\RedisException $e) {
            $this->logger->debug('RelayUserActivityStore: clear failed', [
                'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                'error'  => $e->getMessage(),
            ]);
        }
    }

    private function append(string $pubkeyHex, array $fields): void
    {
        if (!$this->isHex64($pubkeyHex)) {
            return;
        }

        // Coerce all values to strings — Redis Streams require string fields.
        $payload = [];
        foreach ($fields as $k => $v) {
            $payload[$k] = is_string($v) ? $v : (string) $v;
        }

        $key = $this->key($pubkeyHex);
        try {
            // ~ is the "approximate" trim flag — much faster, slight overshoot.
            $this->redis->xAdd($key, '*', $payload, self::MAX_ENTRIES, true);
            $this->redis->expire($key, self::KEY_TTL_SECONDS);
        } catch (\RedisException $e) {
            $this->logger->debug('RelayUserActivityStore: write failed', [
                'pubkey' => substr($pubkeyHex, 0, 8) . '...',
                'type'   => $fields['type'] ?? '?',
                'error'  => $e->getMessage(),
            ]);
        }
    }

    private function key(string $pubkeyHex): string
    {
        return self::KEY_PREFIX . $pubkeyHex;
    }

    private function isHex64(string $value): bool
    {
        return strlen($value) === 64 && ctype_xdigit($value);
    }
}

