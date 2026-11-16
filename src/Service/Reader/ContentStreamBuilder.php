<?php

declare(strict_types=1);

namespace App\Service\Reader;

/**
 * Combines already-selected source records, not arbitrary Nostr events.
 * Authorization remains the responsibility of each source.
 */
final class ContentStreamBuilder
{
    /**
     * @param list<array{type: string, items: array<array-key, object|array<array-key, mixed>>, label?: string}> $sources
     * @return list<array{type: string, key: string, timestamp: int, item: object|array<array-key, mixed>, labels: list<string>}>
     */
    public function build(array $sources): array
    {
        $rows = [];
        foreach ($sources as $source) {
            foreach ($source['items'] as $item) {
                $type = $source['type'];
                if ($type === 'activity' && self::field($item, 'type') === 'highlight') {
                    $type = 'highlight';
                }
                $pubkey = (string) self::field($item, 'pubkey', self::field($item, 'eventPubkey', ''));
                $slug = self::field($item, 'slug', self::field($item, 'dTag'));
                $kind = self::field($item, 'kind');
                $kind = $kind instanceof \BackedEnum ? $kind->value : $kind;
                $coordinate = match ($type) {
                    'article' => $pubkey . ':' . ($kind ?? 30023) . ':' . $slug,
                    'draft' => $pubkey . ':30024:' . $slug,
                    'magazine', 'reading_list' => $pubkey . ':30040:' . $slug,
                    'follow_pack' => $pubkey . ':39089:' . $slug,
                    default => null,
                };
                $eventId = self::field($item, 'eventId', self::field($item, 'id'));
                // Local updates are notifications, not fabricated signed events.
                $key = $coordinate !== null
                    ? 'coordinate:' . $coordinate
                    : $type . ':' . $eventId;
                $date = self::field($item, 'createdAt', self::field($item, 'created_at', self::field($item, 'updatedAt')));
                $timestamp = self::timestamp($date);
                $labels = isset($source['label']) ? [$source['label']] : [];
                if (isset($rows[$key])) {
                    $labels = array_values(array_unique([...$rows[$key]['labels'], ...$labels]));
                    if ($rows[$key]['timestamp'] >= $timestamp) {
                        $rows[$key]['labels'] = $labels;
                        continue;
                    }
                }
                $rows[$key] = [
                    'type' => $type,
                    'key' => $key,
                    'timestamp' => $timestamp,
                    'item' => $item,
                    'labels' => $labels,
                ];
            }
        }

        $rows = array_values($rows);
        usort($rows, static fn (array $a, array $b): int =>
            ($b['timestamp'] <=> $a['timestamp']) ?: strcmp($a['key'], $b['key']));

        return $rows;
    }

    /** @param object|array<array-key, mixed> $item */
    private static function field(object|array $item, string $field, mixed $default = null): mixed
    {
        if (is_array($item)) {
            return $item[$field] ?? $default;
        }
        $getter = 'get' . ucfirst($field);
        return method_exists($item, $getter) ? ($item->$getter() ?? $default) : ($item->$field ?? $default);
    }

    private static function timestamp(mixed $date): int
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->getTimestamp();
        }
        if (is_int($date) || (is_string($date) && ctype_digit($date))) {
            return (int) $date;
        }
        if (is_string($date) && $date !== '') {
            $timestamp = strtotime($date);
            if ($timestamp === false) {
                throw new \UnexpectedValueException('Invalid content stream date.');
            }
            return $timestamp;
        }
        // Some legacy collection snapshots have no date; do not pretend they are new.
        return 0;
    }
}
