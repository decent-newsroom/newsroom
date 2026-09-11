<?php

declare(strict_types=1);

namespace App\Unfold;

use App\Service\Graph\GraphLookupService;
use App\UnfoldBundle\Contract\NostrEvent;
use App\UnfoldBundle\Contract\PublicationTreeLookupInterface;

final readonly class PublicationTreeLookupAdapter implements PublicationTreeLookupInterface
{
    public function __construct(
        private GraphLookupService $graphLookup,
    ) {
    }

    /**
     * @return list<NostrEvent>
     */
    public function findChildren(string $coordinate): array
    {
        return $this->mapRows($this->graphLookup->resolveChildren($this->normalizeCoordinate($coordinate)));
    }

    /**
     * @return list<NostrEvent>
     */
    public function findDescendants(string $coordinate, int $maxDepth): array
    {
        return $this->mapRows($this->graphLookup->resolveDescendants(
            $this->normalizeCoordinate($coordinate),
            $maxDepth,
        ));
    }

    /**
     * @param list<array{current_event_id: string}> $rows
     * @return list<NostrEvent>
     */
    private function mapRows(array $rows): array
    {
        $eventIds = [];
        foreach ($rows as $row) {
            if (isset($row['current_event_id'])) {
                $eventIds[] = (string) $row['current_event_id'];
            }
        }
        $eventIds = array_values(array_unique($eventIds));
        if ($eventIds === []) {
            return [];
        }

        $eventRows = $this->graphLookup->fetchEventRows($eventIds);
        $events = [];
        foreach ($rows as $row) {
            $eventId = (string) ($row['current_event_id'] ?? '');
            $event = $eventRows[$eventId] ?? null;
            if (!is_array($event)) {
                continue;
            }
            $events[] = $this->rowToEvent($event);
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowToEvent(array $row): NostrEvent
    {
        $tags = $row['tags'] ?? [];
        if (is_string($tags)) {
            $tags = json_decode($tags, true);
        }

        return new NostrEvent(
            id: (string) ($row['id'] ?? ''),
            pubkey: strtolower((string) ($row['pubkey'] ?? '')),
            kind: (int) ($row['kind'] ?? 0),
            content: (string) ($row['content'] ?? ''),
            tags: $this->normalizeTags($tags),
            createdAt: (int) ($row['created_at'] ?? 0),
            sig: (string) ($row['sig'] ?? ''),
        );
    }

    /**
     * @param mixed $tags
     * @return list<list<string>>
     */
    private function normalizeTags(mixed $tags): array
    {
        if (!is_array($tags)) {
            return [];
        }

        $normalized = [];
        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            $values = [];
            foreach ($tag as $value) {
                if (!is_scalar($value)) {
                    continue 2;
                }
                $values[] = (string) $value;
            }
            $normalized[] = array_values($values);
        }

        return $normalized;
    }

    private function normalizeCoordinate(string $coordinate): string
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3 || !ctype_digit($parts[0]) || $parts[1] === '' || $parts[2] === '') {
            throw new \InvalidArgumentException(sprintf('Invalid Nostr coordinate: %s', $coordinate));
        }
        $parts[1] = strtolower($parts[1]);

        return implode(':', $parts);
    }
}
