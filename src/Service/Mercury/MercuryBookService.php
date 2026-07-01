<?php

declare(strict_types=1);

namespace App\Service\Mercury;

use App\Enum\KindsEnum;

final class MercuryBookService
{
    private const MAX_SEARCH_RESULTS = 40;
    private const MAX_CHAPTERS = 500;

    public function __construct(
        private readonly MercuryApiClient $apiClient,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query): array
    {
        $events = $this->apiClient->searchPublications($query);
        $books = [];
        $order = [];

        foreach ($events as $event) {
            $book = $this->mapIndexEvent($event);
            if ($book === null) {
                continue;
            }

            $coordinate = $book['coordinate'];
            if (!isset($books[$coordinate])) {
                $books[$coordinate] = $book;
                $order[] = $coordinate;
            } elseif ($book['createdAt'] > $books[$coordinate]['createdAt']) {
                // Mercury can return older replaceable revisions for the same d-tag.
                $books[$coordinate] = $book;
            }

        }

        return array_map(function (string $coordinate) use ($books): array {
            $book = $books[$coordinate];
            unset($book['chapterRefs']);

            return $book;
        }, array_slice($order, 0, self::MAX_SEARCH_RESULTS));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBook(string $eventId): ?array
    {
        $event = $this->apiClient->getEvent($eventId);
        if ($event === null) {
            return null;
        }

        $book = $this->mapIndexEvent($event);
        if ($book === null) {
            return null;
        }

        $allRefs = $book['chapterRefs'];
        $refs = array_slice($allRefs, 0, self::MAX_CHAPTERS);
        $eventIds = array_values(array_filter(array_column($refs, 'eventId'), 'is_string'));
        $chapterEvents = $this->apiClient->getEventsByIds($eventIds);

        $eventsById = [];
        $eventsByCoordinate = [];
        $this->indexChapterEvents($chapterEvents, $eventsById, $eventsByCoordinate);

        $unresolvedAuthors = [];
        foreach ($refs as $ref) {
            $resolved = ($ref['eventId'] !== null && isset($eventsById[$ref['eventId']]))
                || isset($eventsByCoordinate[$ref['coordinate']]);
            if (!$resolved) {
                $unresolvedAuthors[$ref['pubkey']] = true;
            }
        }

        if ($unresolvedAuthors !== []) {
            $fallbackEvents = $this->apiClient->getChaptersByAuthors(
                array_keys($unresolvedAuthors),
                self::MAX_CHAPTERS,
            );
            $this->indexChapterEvents($fallbackEvents, $eventsById, $eventsByCoordinate);
        }

        $chapters = [];
        foreach ($refs as $position => $ref) {
            $chapterEvent = null;
            if ($ref['eventId'] !== null) {
                $chapterEvent = $eventsById[$ref['eventId']] ?? null;
            }
            $chapterEvent ??= $eventsByCoordinate[$ref['coordinate']] ?? null;

            $chapters[] = $this->mapChapter($ref, $chapterEvent, $position + 1);
        }

        $availableChapterCount = count(array_filter(
            $chapters,
            static fn (array $chapter): bool => $chapter['available'],
        ));

        unset($book['chapterRefs']);
        $book['chapters'] = $chapters;
        $book['availableChapterCount'] = $availableChapterCount;
        $book['missingChapterCount'] = count($chapters) - $availableChapterCount;
        $book['truncated'] = count($allRefs) > self::MAX_CHAPTERS;

        return $book;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    private function mapIndexEvent(array $event): ?array
    {
        if ((int) ($event['kind'] ?? 0) !== KindsEnum::PUBLICATION_INDEX->value) {
            return null;
        }

        $tags = $this->normalizeTags($event['tags'] ?? null);
        $chapterRefs = $this->extractChapterRefs($tags);
        if ($chapterRefs === []) {
            return null;
        }

        $id = (string) ($event['id'] ?? '');
        $pubkey = (string) ($event['pubkey'] ?? '');
        $identifier = $this->firstTagValue($tags, 'd') ?? '';
        if ($id === '' || $pubkey === '' || $identifier === '') {
            return null;
        }

        return [
            'id' => $id,
            'coordinate' => sprintf('%d:%s:%s', KindsEnum::PUBLICATION_INDEX->value, $pubkey, $identifier),
            'pubkey' => $pubkey,
            'identifier' => $identifier,
            'title' => $this->firstTagValue($tags, 'title') ?? $identifier,
            'summary' => $this->firstNonEmptyTagValue($tags, ['summary', 'description']),
            'authors' => $this->tagValues($tags, 'author'),
            'coverImage' => $this->httpUrlTag($tags, 'image'),
            'source' => $this->httpUrlTag($tags, 'source'),
            'language' => $this->firstTagValue($tags, 'l'),
            'releaseDate' => $this->firstNonEmptyTagValue($tags, ['release_date', 'published_on']),
            'version' => $this->firstTagValue($tags, 'version'),
            'type' => $this->firstTagValue($tags, 'type') ?? 'book',
            'topics' => $this->tagValues($tags, 't'),
            'createdAt' => (int) ($event['created_at'] ?? 0),
            'chapterCount' => count($chapterRefs),
            'chapterRefs' => $chapterRefs,
        ];
    }

    /**
     * @param array<int, array<int, mixed>> $tags
     * @return array<int, array{coordinate: string, pubkey: string, identifier: string, relay: ?string, eventId: ?string}>
     */
    private function extractChapterRefs(array $tags): array
    {
        $refs = [];
        $seen = [];

        foreach ($tags as $tag) {
            if (($tag[0] ?? null) !== 'a' || !is_string($tag[1] ?? null)) {
                continue;
            }

            $parts = explode(':', $tag[1], 3);
            if (count($parts) !== 3 || (int) $parts[0] !== KindsEnum::PUBLICATION_CONTENT->value) {
                continue;
            }

            $coordinate = $tag[1];
            if (isset($seen[$coordinate])) {
                continue;
            }
            $seen[$coordinate] = true;

            $eventId = is_string($tag[3] ?? null) && preg_match('/^[a-f0-9]{64}$/i', $tag[3]) === 1
                ? strtolower($tag[3])
                : null;
            $relay = is_string($tag[2] ?? null) && str_starts_with($tag[2], 'wss://')
                ? $tag[2]
                : null;

            $refs[] = [
                'coordinate' => $coordinate,
                'pubkey' => $parts[1],
                'identifier' => $parts[2],
                'relay' => $relay,
                'eventId' => $eventId,
            ];
        }

        return $refs;
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @param array<string, array<string, mixed>> $eventsById
     * @param array<string, array<string, mixed>> $eventsByCoordinate
     */
    private function indexChapterEvents(array $events, array &$eventsById, array &$eventsByCoordinate): void
    {
        foreach ($events as $event) {
            if ((int) ($event['kind'] ?? 0) !== KindsEnum::PUBLICATION_CONTENT->value) {
                continue;
            }

            $id = (string) ($event['id'] ?? '');
            $pubkey = (string) ($event['pubkey'] ?? '');
            $tags = $this->normalizeTags($event['tags'] ?? null);
            $identifier = $this->firstTagValue($tags, 'd');
            if ($id === '' || $pubkey === '' || $identifier === null) {
                continue;
            }

            $eventsById[$id] = $event;
            $coordinate = sprintf('%d:%s:%s', KindsEnum::PUBLICATION_CONTENT->value, $pubkey, $identifier);
            $current = $eventsByCoordinate[$coordinate] ?? null;
            if ($current === null || (int) ($event['created_at'] ?? 0) > (int) ($current['created_at'] ?? 0)) {
                $eventsByCoordinate[$coordinate] = $event;
            }
        }
    }

    /**
     * @param array{coordinate: string, pubkey: string, identifier: string, relay: ?string, eventId: ?string} $ref
     * @param array<string, mixed>|null $event
     * @return array<string, mixed>
     */
    private function mapChapter(array $ref, ?array $event, int $position): array
    {
        if ($event === null) {
            return [
                ...$ref,
                'position' => $position,
                'available' => false,
                'title' => $this->humanizeIdentifier($ref['identifier']),
                'summary' => null,
                'content' => null,
                'id' => null,
                'createdAt' => null,
            ];
        }

        $tags = $this->normalizeTags($event['tags'] ?? null);

        return [
            ...$ref,
            'position' => $position,
            'available' => true,
            'title' => $this->firstTagValue($tags, 'title') ?? $this->humanizeIdentifier($ref['identifier']),
            'summary' => $this->firstNonEmptyTagValue($tags, ['summary', 'description']),
            'content' => (string) ($event['content'] ?? ''),
            'id' => (string) ($event['id'] ?? ''),
            'createdAt' => (int) ($event['created_at'] ?? 0),
        ];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function normalizeTags(mixed $tags): array
    {
        if (!is_array($tags)) {
            return [];
        }

        return array_values(array_filter($tags, 'is_array'));
    }

    /**
     * @param array<int, array<int, mixed>> $tags
     */
    private function firstTagValue(array $tags, string $name): ?string
    {
        foreach ($tags as $tag) {
            if (($tag[0] ?? null) === $name && is_string($tag[1] ?? null)) {
                return trim($tag[1]);
            }
        }

        return null;
    }

    /**
     * @param array<int, array<int, mixed>> $tags
     * @param string[] $names
     */
    private function firstNonEmptyTagValue(array $tags, array $names): ?string
    {
        foreach ($names as $name) {
            $value = $this->firstTagValue($tags, $name);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<int, array<int, mixed>> $tags
     * @return string[]
     */
    private function tagValues(array $tags, string $name): array
    {
        $values = [];
        foreach ($tags as $tag) {
            if (($tag[0] ?? null) !== $name || !is_string($tag[1] ?? null)) {
                continue;
            }

            $value = trim($tag[1]);
            if ($value !== '' && !in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<int, array<int, mixed>> $tags
     */
    private function httpUrlTag(array $tags, string $name): ?string
    {
        $url = $this->firstTagValue($tags, $name);
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : null;
    }

    private function humanizeIdentifier(string $identifier): string
    {
        $value = preg_replace('/^pg\d+-chapter-\d+-?/i', '', $identifier) ?? $identifier;
        $value = trim(str_replace(['-', '_'], ' ', $value));
        $value = mb_convert_case($value, MB_CASE_TITLE);
        $value = preg_replace_callback(
            '/\b[ivxlcdm]+\b/i',
            static fn (array $match): string => strtoupper($match[0]),
            $value,
        ) ?? $value;

        return $value !== '' ? $value : $identifier;
    }
}
