<?php

declare(strict_types=1);

namespace App\Unfold;

use DecentNewsroom\UnfoldBundle\Contract\InteractionDelivery;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Durable SQL outbox for signed reader interactions (comments/replies/likes/
 * reposts) on Unfold publications. Backed by plain DBAL (no ORM entity) so it
 * can be written atomically alongside Doctrine ORM projection inside a single
 * {@see Connection::transactional()} block (see SignedInteractionPublisher).
 *
 * Responsibilities:
 *   - Idempotent insert keyed by the signed event id (replay-safe).
 *   - Bounded automatic retry bookkeeping (attempts/next_attempt_at/backoff).
 *   - Crash-safe recovery via a short, row-scoped lease claim — never a
 *     long-held, network-blocking database lock.
 *   - Reader-ownership + target/publication identity lookups for status/retry.
 *
 * Table: reader_interaction_outbox (see migrations/Version20261003170000.php)
 */
class InteractionOutboxStore
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';
    public const TERMINAL_TARGET_ERROR = 'unfold_interactions.target_unavailable';

    public const DEFAULT_MAX_ATTEMPTS = 3;
    public const DEFAULT_LEASE_SECONDS = 900;
    public const DEFAULT_DISPATCH_LEASE_SECONDS = 120;

    /** Backoff applied after attempt N (1-indexed), before attempt N+1. */
    private const BACKOFF_SECONDS = [60, 300, 900];

    private const TABLE = 'reader_interaction_outbox';

    public function __construct(private readonly Connection $connection) {}

    public static function backoffSeconds(int $completedAttempts): int
    {
        $index = max(0, $completedAttempts - 1);
        return self::BACKOFF_SECONDS[$index] ?? self::BACKOFF_SECONDS[array_key_last(self::BACKOFF_SECONDS)];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEventId(string $eventId, bool $forUpdate = false): ?array
    {
        $sql = 'SELECT * FROM ' . self::TABLE . ' WHERE event_id = :event_id' . ($forUpdate ? ' FOR UPDATE' : '');
        $row = $this->connection->fetchAssociative($sql, ['event_id' => $eventId]);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Owner + target + publication identity lookup used by status()/retry().
     * Never returns a row belonging to a different reader or a different
     * target/publication, even when the event id itself is correct.
     *
     * @return array<string, mixed>|null
     */
    public function findOwned(string $eventId, string $readerPubkey, string $targetCoordinate, string $publicationCoordinate): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ' . self::TABLE . '
             WHERE event_id = :event_id
               AND reader_pubkey = :reader_pubkey
               AND target_coordinate = :target_coordinate
               AND publication_coordinate = :publication_coordinate',
            [
                'event_id' => $eventId,
                'reader_pubkey' => $readerPubkey,
                'target_coordinate' => $targetCoordinate,
                'publication_coordinate' => $publicationCoordinate,
            ],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Insert a new queued row. Caller must have already checked
     * {@see findByEventId()} for an existing row (ideally with a `FOR UPDATE`
     * lock) inside the same transaction as the local projection, so replayed
     * event ids never produce a second row or mutate the signed payload.
     *
     * @param array<string, mixed> $signedEvent
     * @param list<string> $relays
     * @return array<string, mixed>
     */
    public function insertQueued(
        string $eventId,
        string $readerPubkey,
        string $publicationCoordinate,
        string $targetCoordinate,
        int $kind,
        string $action,
        array $signedEvent,
        array $relays,
    ): array {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->executeStatement(
            'INSERT INTO ' . self::TABLE . '
                (event_id, reader_pubkey, publication_coordinate, target_coordinate, kind, action,
                 signed_event, relays, status, relay_results, attempts, max_attempts,
                 next_attempt_at, leased_until, last_error, created_at, updated_at)
             VALUES
                (:event_id, :reader_pubkey, :publication_coordinate, :target_coordinate, :kind, :action,
                 :signed_event, :relays, :status, :relay_results, 0, :max_attempts,
                 :next_attempt_at, NULL, NULL, :created_at, :updated_at)',
            [
                'event_id' => $eventId,
                'reader_pubkey' => $readerPubkey,
                'publication_coordinate' => $publicationCoordinate,
                'target_coordinate' => $targetCoordinate,
                'kind' => $kind,
                'action' => $action,
                'signed_event' => json_encode($signedEvent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'relays' => json_encode($relays, JSON_THROW_ON_ERROR),
                'status' => self::STATUS_QUEUED,
                'relay_results' => $this->encodeMap([]),
                'max_attempts' => self::DEFAULT_MAX_ATTEMPTS,
                'next_attempt_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['kind' => ParameterType::INTEGER, 'max_attempts' => ParameterType::INTEGER],
        );

        return $this->findByEventId($eventId)
            ?? throw new \RuntimeException('Reader interaction outbox row vanished immediately after insert');
    }

    /**
     * Claim up to $limit due rows (status=queued, next_attempt_at reached,
     * not currently leased) for recovery dispatch. Uses `FOR UPDATE SKIP
     * LOCKED` inside a short transaction so concurrent recovery-command runs
     * never block on each other and no lock is held across network I/O.
     *
     * @return list<array{event_id: string, dispatch_lease: string}>
     */
    public function claimDue(int $limit = 200, int $leaseSeconds = self::DEFAULT_DISPATCH_LEASE_SECONDS): array
    {
        return $this->connection->transactional(function () use ($limit, $leaseSeconds): array {
            $now = new \DateTimeImmutable();
            $rows = $this->connection->fetchAllAssociative(
                'SELECT event_id FROM ' . self::TABLE . '
                 WHERE status = :status
                   AND next_attempt_at <= :now
                   AND (leased_until IS NULL OR leased_until < :now)
                 ORDER BY next_attempt_at ASC
                 LIMIT :limit
                 FOR UPDATE SKIP LOCKED',
                ['status' => self::STATUS_QUEUED, 'now' => $now->format('Y-m-d H:i:s'), 'limit' => $limit],
                ['limit' => ParameterType::INTEGER],
            );

            $ids = array_map(static fn (array $row): string => (string) $row['event_id'], $rows);
            if ($ids === []) {
                return [];
            }

            $leasedUntil = $now->modify(sprintf('+%d seconds', $leaseSeconds))->format('Y-m-d H:i:s');
            $this->connection->executeStatement(
                'UPDATE ' . self::TABLE . ' SET leased_until = :leased_until WHERE event_id IN (:ids)',
                ['leased_until' => $leasedUntil, 'ids' => $ids],
                ['ids' => ArrayParameterType::STRING],
            );

            return array_map(static fn (string $id): array => ['event_id' => $id, 'dispatch_lease' => $leasedUntil], $ids);
        });
    }

    /**
     * Claim a single row for immediate delivery (called from the message
     * handler). Returns null when the row is missing, already resolved
     * (published/partial/failed), or currently leased by another worker —
     * the caller must treat that as "nothing to do", not an error.
     *
     * @return array<string, mixed>|null
     */
    public function claimForDelivery(string $eventId, int $leaseSeconds = self::DEFAULT_LEASE_SECONDS, ?string $dispatchLease = null): ?array
    {
        return $this->connection->transactional(function () use ($eventId, $leaseSeconds, $dispatchLease): ?array {
            $row = $this->connection->fetchAssociative(
                'SELECT * FROM ' . self::TABLE . ' WHERE event_id = :event_id FOR UPDATE',
                ['event_id' => $eventId],
            );
            if ($row === false) {
                return null;
            }

            $hydrated = $this->hydrate($row);
            if ($hydrated['status'] !== self::STATUS_QUEUED) {
                return null; // already terminal (published/partial/failed)
            }

            $now = new \DateTimeImmutable();
            $ownsDispatchLease = $dispatchLease !== null
                && $hydrated['leased_until']?->format('Y-m-d H:i:s') === $dispatchLease;
            if (($dispatchLease !== null && !$ownsDispatchLease)
                || (!$ownsDispatchLease && $hydrated['leased_until'] instanceof \DateTimeImmutable && $hydrated['leased_until'] > $now)
                || new \DateTimeImmutable($hydrated['next_attempt_at']) > $now) {
                return null; // held by another worker — not a crash, just a race
            }

            $deadline = $now->modify(sprintf('+%d seconds', $leaseSeconds));
            if ($ownsDispatchLease && $deadline <= $hydrated['leased_until']) {
                $deadline = $hydrated['leased_until']->modify('+1 second');
            }
            $until = $deadline->format('Y-m-d H:i:s');
            $this->connection->executeStatement(
                'UPDATE ' . self::TABLE . ' SET leased_until = :until WHERE event_id = :event_id',
                ['until' => $until, 'event_id' => $eventId],
            );
            $hydrated['leased_until'] = new \DateTimeImmutable($until);

            return $hydrated;
        });
    }

    /**
     * Persist the outcome of one delivery attempt. Always clears the lease.
     * Pass $nextDelaySeconds when the row should remain queued for another
     * automatic attempt; pass null for a terminal outcome (published, or
     * partial/failed once attempts are exhausted).
     *
     * @param array<string, array{ok: bool, message: ?string, latency_ms: ?int}> $relayResults
     */
    public function recordAttempt(
        string $eventId,
        string $status,
        array $relayResults,
        int $attempts,
        ?string $error,
        ?int $nextDelaySeconds,
        ?\DateTimeImmutable $leaseUntil = null,
    ): void {
        $now = new \DateTimeImmutable();
        $params = [
            'status' => $status,
            'relay_results' => $this->encodeMap($relayResults),
            'attempts' => $attempts,
            'last_error' => $error,
            'updated_at' => $now->format('Y-m-d H:i:s'),
            'event_id' => $eventId,
        ];
        $types = ['attempts' => ParameterType::INTEGER];

        if ($nextDelaySeconds !== null) {
            $params['next_attempt_at'] = $now->modify(sprintf('+%d seconds', $nextDelaySeconds))->format('Y-m-d H:i:s');
            $sql = 'UPDATE ' . self::TABLE . '
                    SET status = :status, relay_results = :relay_results, attempts = :attempts,
                        last_error = :last_error, leased_until = NULL, updated_at = :updated_at,
                        next_attempt_at = :next_attempt_at
                    WHERE event_id = :event_id';
        } else {
            $sql = 'UPDATE ' . self::TABLE . '
                    SET status = :status, relay_results = :relay_results, attempts = :attempts,
                        last_error = :last_error, leased_until = NULL, updated_at = :updated_at
                    WHERE event_id = :event_id';
        }

        if ($leaseUntil !== null) {
            $sql .= ' AND leased_until = :expected_lease';
            $params['expected_lease'] = $leaseUntil->format('Y-m-d H:i:s');
        }
        $changed = $this->connection->executeStatement($sql, $params, $types);
        if ($leaseUntil !== null && $changed !== 1) {
            throw new \RuntimeException('Reader interaction delivery lease was superseded');
        }
    }

    /**
     * Re-queue an existing failed/partial row for a fresh bounded retry
     * budget, requested explicitly by the reader (not an automatic retry).
     * No-op (returns the row unchanged) when the row is already queued
     * (an automatic retry is already pending) or published (nothing to do).
     * Never mutates the stored signed_event payload.
     *
     * @return array<string, mixed>|null Null only when the row does not exist.
     */
    public function requeueForRetry(string $eventId): ?array
    {
        return $this->connection->transactional(function () use ($eventId): ?array {
            $row = $this->connection->fetchAssociative(
                'SELECT * FROM ' . self::TABLE . ' WHERE event_id = :event_id FOR UPDATE',
                ['event_id' => $eventId],
            );
            if ($row === false) {
                return null;
            }

            $hydrated = $this->hydrate($row);
            if (!in_array($hydrated['status'], [self::STATUS_FAILED, self::STATUS_PARTIAL], true)) {
                return $hydrated;
            }

            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            $this->connection->executeStatement(
                'UPDATE ' . self::TABLE . '
                 SET status = :status, attempts = 0, leased_until = NULL,
                     next_attempt_at = :now, last_error = NULL, updated_at = :now
                 WHERE event_id = :event_id',
                ['status' => self::STATUS_QUEUED, 'now' => $now, 'event_id' => $eventId],
            );

            $hydrated['status'] = self::STATUS_QUEUED;
            $hydrated['attempts'] = 0;
            $hydrated['leased_until'] = null;
            $hydrated['last_error'] = null;

            return $hydrated;
        });
    }

    /**
     * @param array<string, mixed> $row A row as returned by this store (already hydrated)
     */
    public static function toDelivery(array $row): InteractionDelivery
    {
        return new InteractionDelivery(
            eventId: (string) $row['event_id'],
            status: (string) $row['status'],
            localCommit: true,
            relayResults: is_array($row['relay_results']) ? $row['relay_results'] : [],
            error: $row['last_error'] !== null ? (string) $row['last_error'] : null,
            retryable: ($row['last_error'] ?? null) === self::TERMINAL_TARGET_ERROR ? false : null,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $row['signed_event'] = $this->decodeJson($row['signed_event']);
        $row['relays'] = $this->decodeJson($row['relays']) ?? [];
        $row['relay_results'] = $this->decodeJson($row['relay_results']) ?? [];
        $row['kind'] = (int) $row['kind'];
        $row['attempts'] = (int) $row['attempts'];
        $row['max_attempts'] = (int) $row['max_attempts'];
        $row['leased_until'] = $row['leased_until'] !== null && $row['leased_until'] !== ''
            ? new \DateTimeImmutable((string) $row['leased_until'])
            : null;

        return $row;
    }

    private function decodeJson(mixed $value): mixed
    {
        if (is_array($value) || $value === null) {
            return $value;
        }

        return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Encodes an associative map (keyed by relay URL) as a JSON object,
     * never a JSON array, even when empty — keeps the JSONB column
     * consistently object-shaped as declared by the migration's `'{}'`
     * default.
     *
     * @param array<string, mixed> $map
     */
    private function encodeMap(array $map): string
    {
        return json_encode($map === [] ? new \stdClass() : $map, JSON_THROW_ON_ERROR);
    }
}
