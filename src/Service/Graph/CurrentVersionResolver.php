<?php

declare(strict_types=1);

namespace App\Service\Graph;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Determines which event version is current for a replaceable/parameterized-replaceable record.
 *
 * Current-version rule: highest created_at wins.
 * Tie-break: lexicographically lower event id wins.
 *
 * Uses INSERT … ON CONFLICT DO UPDATE for atomic upsert.
 */
class CurrentVersionResolver
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RecordIdentityService $identityService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Attempt to set the given event as current for its coordinate.
     *
     * Only updates if the event is newer (or same age but lower event id).
     *
     * @return bool True if this event became (or already was) the current version.
     */
    public function updateIfCurrent(
        string $eventId,
        int $kind,
        string $pubkey,
        ?string $dTag,
        int $createdAt,
    ): bool {
        $coord = null;
        $recordUid = null;

        if ($this->identityService->isParameterizedReplaceable($kind)) {
            $coord = $this->identityService->deriveCoordinate($kind, $pubkey, $dTag);
            $recordUid = 'coord:' . $coord;
        } elseif ($this->identityService->isReplaceable($kind)) {
            $coord = $this->identityService->deriveReplaceableCoordinate($kind, $pubkey);
            $recordUid = 'coord:' . $coord;
        } else {
            // Non-replaceable events don't have a "current" concept
            return false;
        }

        if ($coord === null || $recordUid === null) {
            return false;
        }

        // Atomic upsert with tie-break:
        // INSERT if no row exists.
        // UPDATE only if incoming (created_at, event_id) wins over existing.
        $sql = <<<'SQL'
            INSERT INTO current_record (record_uid, coord, kind, pubkey, d_tag, current_event_id, current_created_at, updated_at)
            VALUES (:record_uid, :coord, :kind, :pubkey, :d_tag, :event_id, :created_at, NOW())
            ON CONFLICT (record_uid) DO UPDATE SET
                current_event_id = EXCLUDED.current_event_id,
                current_created_at = EXCLUDED.current_created_at,
                updated_at = NOW()
            WHERE
                current_record.current_created_at < EXCLUDED.current_created_at
                OR (
                    current_record.current_created_at = EXCLUDED.current_created_at
                    AND current_record.current_event_id > EXCLUDED.current_event_id
                )
        SQL;

        $affected = $this->connection->executeStatement($sql, [
            'record_uid' => $recordUid,
            'coord' => $coord,
            'kind' => $kind,
            'pubkey' => strtolower($pubkey),
            'd_tag' => $this->identityService->isParameterizedReplaceable($kind) ? ($dTag ?? '') : null,
            'event_id' => $eventId,
            'created_at' => $createdAt,
        ]);

        // affected = 1 means inserted or updated (became current)
        // affected = 0 means existing row is already newer
        $becameCurrent = $affected > 0;

        if ($becameCurrent) {
            $this->logger->debug('CurrentVersionResolver: event became current', [
                'coord' => $coord,
                'event_id' => substr($eventId, 0, 16) . '...',
                'created_at' => $createdAt,
            ]);
        }

        return $becameCurrent;
    }

    /**
     * Resolve the current event ID for a given coordinate.
     *
     * @return string|null The current event id, or null if no record exists.
     */
    public function resolveCurrentEventId(string $coord): ?string
    {
        $sql = 'SELECT current_event_id FROM current_record WHERE coord = :coord';
        $result = $this->connection->fetchOne($sql, ['coord' => $coord]);

        return $result !== false ? $result : null;
    }

    /**
     * Resolve current event IDs for multiple coordinates in one query.
     *
     * @param string[] $coords
     * @return array<string, string> coord → event_id
     */
    public function resolveCurrentEventIds(array $coords): array
    {
        if (empty($coords)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($coords), '?'));
        $sql = "SELECT coord, current_event_id FROM current_record WHERE coord IN ({$placeholders})";
        $rows = $this->connection->fetchAllAssociative($sql, array_values($coords));

        $map = [];
        foreach ($rows as $row) {
            $map[$row['coord']] = $row['current_event_id'];
        }

        return $map;
    }

    /**
     * Get the full current record for a coordinate.
     *
     * @return array{record_uid: string, coord: string, kind: int, pubkey: string, d_tag: ?string, current_event_id: string, current_created_at: int}|null
     */
    public function getCurrentRecord(string $coord): ?array
    {
        $sql = 'SELECT * FROM current_record WHERE coord = :coord';
        $row = $this->connection->fetchAssociative($sql, ['coord' => $coord]);

        return $row !== false ? $row : null;
    }
}

