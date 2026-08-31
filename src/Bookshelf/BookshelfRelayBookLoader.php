<?php

declare(strict_types=1);

namespace App\Bookshelf;

use App\Service\Nostr\NostrClient;
use Psr\Log\LoggerInterface;

/**
 * Resolves Nostr-native book publications that are not present in the Books index.
 */
final class BookshelfRelayBookLoader
{
    private const PUBLICATION_INDEX_KIND = 30040;
    private const PUBLICATION_CONTENT_KIND = 30041;

    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<int, array{type: 'a'|'e', coordinate: ?string, relay: ?string, eventId: ?string, pubkey: ?string}> $references
     * @param array<int, array<string, mixed>> $indexedBooks
     * @return array<int, array<string, mixed>>
     */
    public function fillMissingBooks(array $references, array $indexedBooks): array
    {
        if ($references === []) {
            return [];
        }

        $booksById = [];
        $booksByCoordinate = [];
        foreach ($indexedBooks as $book) {
            if (is_string($book['id'] ?? null) && $book['id'] !== '') {
                $booksById[$book['id']] = $book;
            }
            if (is_string($book['coordinate'] ?? null) && $book['coordinate'] !== '') {
                $booksByCoordinate[$book['coordinate']] = $book;
            }
        }

        [$eventIds, $coordinates, $relayHints] = $this->missingReferences($references, $booksById, $booksByCoordinate);
        $eventsById = $this->fetchById($eventIds, $relayHints);
        $eventsByCoordinate = $this->fetchByCoordinate($coordinates, $relayHints);

        foreach ($eventsById as $event) {
            $book = $this->mapBook($event, null);
            if ($book !== null) {
                $booksById[$book['id']] = $book;
                $booksByCoordinate[$book['coordinate']] = $book;
            }
        }
        foreach ($eventsByCoordinate as $event) {
            $book = $this->mapBook($event, null);
            if ($book !== null) {
                $booksById[$book['id']] = $book;
                $booksByCoordinate[$book['coordinate']] = $book;
            }
        }

        return $this->orderedBooks($references, $booksById, $booksByCoordinate);
    }
    /** @return array<string, mixed>|null */
    public function getBook(string $eventId): ?array
    {
        $events = $this->fetchById([$eventId], []);
        $event = $events[$eventId] ?? reset($events);
        if (!$event instanceof \stdClass && !is_object($event)) {
            return null;
        }

        $book = $this->mapBook($event, null);
        if ($book === null) {
            return null;
        }

        $refs = $this->chapterReferences($this->normalizeTags($event->tags ?? []));
        $book['chapters'] = array_map(static fn (array $ref, int $position): array => [
            ...$ref,
            'position' => $position,
            'available' => false,
            'title' => $ref['identifier'],
            'summary' => null,
            'content' => null,
            'id' => null,
            'createdAt' => null,
        ], $refs, array_keys($refs));
        $book['availableChapterCount'] = 0;
        $book['missingChapterCount'] = count($refs);
        $book['truncated'] = false;

        return $this->fillMissingChapters($book);
    }

    /** @param array<string, mixed> $book
     *  @return array<string, mixed>
     */
    public function fillMissingChapters(array $book): array
    {
        $chapters = is_array($book['chapters'] ?? null) ? $book['chapters'] : [];
        $coordinates = [];
        $relayHints = [];
        foreach ($chapters as $chapter) {
            if (!is_array($chapter) || ($chapter['available'] ?? false) === true) {
                continue;
            }
            if (is_string($chapter['coordinate'] ?? null) && $this->isChapterCoordinate($chapter['coordinate'])) {
                $coordinates[$chapter['coordinate']] = true;
            }
            if (is_string($chapter['relay'] ?? null) && $this->isRelayUrl($chapter['relay'])) {
                $relayHints[$chapter['relay']] = true;
            }
        }

        $eventsByCoordinate = [];
        foreach ($this->fetchByCoordinate(array_keys($coordinates), array_keys($relayHints)) as $event) {
            $coordinate = $this->eventCoordinate($event, self::PUBLICATION_CONTENT_KIND);
            if ($coordinate !== null) {
                $eventsByCoordinate[$coordinate] = $event;
            }
        }

        foreach ($chapters as $position => &$chapter) {
            if (!is_array($chapter) || ($chapter['available'] ?? false) === true) {
                continue;
            }
            $event = is_string($chapter['coordinate'] ?? null) ? ($eventsByCoordinate[$chapter['coordinate']] ?? null) : null;
            if ($event === null) {
                continue;
            }

            $tags = $this->normalizeTags($event->tags ?? []);
            $chapter['available'] = true;
            $chapter['title'] = $this->firstTagValue($tags, 'title') ?? (string) ($chapter['identifier'] ?? '');
            $chapter['summary'] = $this->firstNonEmptyTagValue($tags, ['summary', 'description']);
            $chapter['content'] = (string) ($event->content ?? '');
            $chapter['id'] = (string) ($event->id ?? '');
            $chapter['createdAt'] = (int) ($event->created_at ?? 0);
            $chapter['position'] ??= $position + 1;
        }
        unset($chapter);

        $book['chapters'] = $chapters;
        $book['availableChapterCount'] = count(array_filter($chapters, static fn (mixed $chapter): bool => is_array($chapter) && ($chapter['available'] ?? false) === true));
        $book['missingChapterCount'] = count($chapters) - $book['availableChapterCount'];

        return $book;
    }

    /**
     * @param array<int, array{type: 'a'|'e', coordinate: ?string, relay: ?string, eventId: ?string, pubkey: ?string}> $references
     * @param array<string, array<string, mixed>> $booksById
     * @param array<string, array<string, mixed>> $booksByCoordinate
     * @return array{string[], string[], string[]}
     */
    private function missingReferences(array $references, array $booksById, array $booksByCoordinate): array
    {
        $eventIds = [];
        $coordinates = [];
        $relayHints = [];
        foreach ($references as $reference) {
            $eventId = $reference['eventId'];
            $coordinate = $reference['coordinate'];
            $isResolved = ($eventId !== null && isset($booksById[$eventId]))
                || ($coordinate !== null && isset($booksByCoordinate[$coordinate]));
            if ($isResolved) {
                continue;
            }

            if (is_string($eventId) && $eventId !== '') {
                $eventIds[$eventId] = true;
            }
            if (is_string($coordinate) && $this->isPublicationCoordinate($coordinate)) {
                $coordinates[$coordinate] = true;
            }
            if (is_string($reference['relay']) && $this->isRelayUrl($reference['relay'])) {
                $relayHints[$reference['relay']] = true;
            }
        }

        return [array_keys($eventIds), array_keys($coordinates), array_keys($relayHints)];
    }

    /** @param string[] $eventIds
     *  @param string[] $relayHints
     *  @return array<string, object>
     */
    private function fetchById(array $eventIds, array $relayHints): array
    {
        if ($eventIds === []) {
            return [];
        }

        try {
            return $this->nostrClient->getEventsByIds($eventIds, $relayHints);
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not fetch Nostr-native bookshelf books by event ID.', [
                'event_count' => count($eventIds),
                'exception' => $exception,
            ]);

            return [];
        }
    }

    /** @param string[] $coordinates
     *  @param string[] $relayHints
     *  @return array<string, object>
     */
    private function fetchByCoordinate(array $coordinates, array $relayHints): array
    {
        if ($coordinates === []) {
            return [];
        }

        try {
            return $this->nostrClient->getEventsByCoordinates($coordinates, $relayHints);
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not fetch Nostr-native bookshelf books by coordinate.', [
                'coordinate_count' => count($coordinates),
                'exception' => $exception,
            ]);

            return [];
        }
    }

    /**
     * @param array<int, array{type: 'a'|'e', coordinate: ?string, relay: ?string, eventId: ?string, pubkey: ?string}> $references
     * @param array<string, array<string, mixed>> $booksById
     * @param array<string, array<string, mixed>> $booksByCoordinate
     * @return array<int, array<string, mixed>>
     */
    private function orderedBooks(array $references, array $booksById, array $booksByCoordinate): array
    {
        $books = [];
        $seen = [];
        foreach ($references as $reference) {
            $book = $reference['eventId'] !== null ? ($booksById[$reference['eventId']] ?? null) : null;
            $book ??= $reference['coordinate'] !== null ? ($booksByCoordinate[$reference['coordinate']] ?? null) : null;
            if ($book === null) {
                continue;
            }

            if (($book['relay'] ?? null) === null && is_string($reference['relay']) && $this->isRelayUrl($reference['relay'])) {
                $book['relay'] = $reference['relay'];
            }

            $deduplicationKey = (string) ($book['coordinate'] ?? $book['id'] ?? '');
            if ($deduplicationKey === '' || isset($seen[$deduplicationKey])) {
                continue;
            }

            $seen[$deduplicationKey] = true;
            $books[] = $book;
        }

        return $books;
    }

    /** @return array<string, mixed>|null */
    private function mapBook(object $event, ?string $relay): ?array
    {
        if ((int) ($event->kind ?? 0) !== self::PUBLICATION_INDEX_KIND) {
            return null;
        }

        $tags = $this->normalizeTags($event->tags ?? []);
        $identifier = $this->firstTagValue($tags, 'd');
        $id = (string) ($event->id ?? '');
        $pubkey = (string) ($event->pubkey ?? '');
        $chapterCount = $this->chapterCount($tags);
        if ($id === '' || $pubkey === '' || $identifier === null || $identifier === '' || $chapterCount === 0) {
            return null;
        }

        return [
            'id' => $id,
            'coordinate' => sprintf('%d:%s:%s', self::PUBLICATION_INDEX_KIND, $pubkey, $identifier),
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
            'relay' => $relay,
            'createdAt' => (int) ($event->created_at ?? 0),
            'chapterCount' => $chapterCount,
        ];
    }

    /**
     * @param array<int, array<int, mixed>> $tags
     * @return array<int, array{coordinate: string, pubkey: string, identifier: string, relay: ?string, eventId: ?string}>
     */
    private function chapterReferences(array $tags): array
    {
        $references = [];
        $seen = [];
        foreach ($tags as $tag) {
            if (($tag[0] ?? null) !== 'a' || !is_string($tag[1] ?? null) || !$this->isChapterCoordinate($tag[1])) {
                continue;
            }
            if (isset($seen[$tag[1]])) {
                continue;
            }

            [, $pubkey, $identifier] = explode(':', $tag[1], 3);
            $seen[$tag[1]] = true;
            $references[] = [
                'coordinate' => $tag[1],
                'pubkey' => $pubkey,
                'identifier' => $identifier,
                'relay' => is_string($tag[2] ?? null) && $this->isRelayUrl($tag[2]) ? $tag[2] : null,
                'eventId' => is_string($tag[3] ?? null) && preg_match('/^[a-f0-9]{64}$/i', $tag[3]) === 1 ? strtolower($tag[3]) : null,
            ];
        }

        return $references;
    }

    private function isChapterCoordinate(string $coordinate): bool
    {
        $parts = explode(':', $coordinate, 3);

        return count($parts) === 3 && (int) $parts[0] === self::PUBLICATION_CONTENT_KIND && $parts[1] !== '' && $parts[2] !== '';
    }

    private function eventCoordinate(object $event, int $kind): ?string
    {
        if ((int) ($event->kind ?? 0) !== $kind) {
            return null;
        }

        $pubkey = (string) ($event->pubkey ?? '');
        $identifier = $this->firstTagValue($this->normalizeTags($event->tags ?? []), 'd');

        return $pubkey !== '' && $identifier !== null && $identifier !== ''
            ? sprintf('%d:%s:%s', $kind, $pubkey, $identifier)
            : null;
    }

    private function isPublicationCoordinate(string $coordinate): bool
    {
        $parts = explode(':', $coordinate, 3);

        return count($parts) === 3 && (int) $parts[0] === self::PUBLICATION_INDEX_KIND && $parts[1] !== '' && $parts[2] !== '';
    }

    private function isRelayUrl(string $relay): bool
    {
        $scheme = strtolower((string) parse_url($relay, PHP_URL_SCHEME));

        return in_array($scheme, ['ws', 'wss'], true) && (string) parse_url($relay, PHP_URL_HOST) !== '';
    }

    /** @return array<int, array<int, mixed>> */
    private function normalizeTags(mixed $tags): array
    {
        if (!is_array($tags)) {
            return [];
        }

        $normalized = [];
        foreach ($tags as $tag) {
            if ($tag instanceof \stdClass) {
                $tag = (array) $tag;
            }
            if (is_array($tag)) {
                $normalized[] = array_values($tag);
            }
        }

        return $normalized;
    }

    /** @param array<int, array<int, mixed>> $tags */
    private function chapterCount(array $tags): int
    {
        $coordinates = [];
        foreach ($tags as $tag) {
            if (($tag[0] ?? null) === 'a' && is_string($tag[1] ?? null) && str_starts_with($tag[1], self::PUBLICATION_CONTENT_KIND . ':')) {
                $coordinates[$tag[1]] = true;
            }
        }

        return count($coordinates);
    }

    /** @param array<int, array<int, mixed>> $tags */
    private function firstTagValue(array $tags, string $name): ?string
    {
        foreach ($tags as $tag) {
            if (($tag[0] ?? null) === $name && is_string($tag[1] ?? null)) {
                return trim($tag[1]);
            }
        }

        return null;
    }

    /** @param array<int, array<int, mixed>> $tags
     *  @param string[] $names
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

    /** @param array<int, array<int, mixed>> $tags
     *  @return string[]
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

    /** @param array<int, array<int, mixed>> $tags */
    private function httpUrlTag(array $tags, string $name): ?string
    {
        $url = $this->firstTagValue($tags, $name);
        if ($url === null || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) || (string) parse_url($url, PHP_URL_HOST) === '') {
            return null;
        }

        return $url;
    }
}
