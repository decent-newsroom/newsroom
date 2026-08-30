<?php

declare(strict_types=1);

namespace App\Bookshelf;

use App\Api\Books\Dto\Nip01Filter;
use App\Api\Books\Elasticsearch\BooksIndex;
use App\Api\Books\Elasticsearch\EventQueryBuilder;
use App\Api\Books\Presenter\NostrEventPresenter;

/**
 * Direct Elasticsearch fallback for My Books when neither remote nor local HTTP
 * book resolution is available.
 */
final class BookshelfEsBookLoader
{
    private const PUBLICATION_INDEX_KIND = 30040;
    private const PUBLICATION_CONTENT_KIND = 30041;
    private const MAX_RESULTS = 100;

    public function __construct(
        private readonly BooksIndex $booksIndex,
        private readonly EventQueryBuilder $eventQueryBuilder,
        private readonly NostrEventPresenter $eventPresenter,
    ) {
    }

    /**
     * @param array<int, array{type: 'a'|'e', coordinate: ?string, relay: ?string, eventId: ?string, pubkey: ?string}> $references
     * @return array<int, array<string, mixed>>
     */
    public function getBooksForReferences(array $references): array
    {
        $eventsById = [];
        $eventsByCoordinate = [];
        $eventIds = array_values(array_unique(array_filter(array_column($references, 'eventId'), 'is_string')));

        if ($eventIds !== []) {
            $this->indexEvents($this->queryEvents([
                'ids' => $eventIds,
                'kinds' => [self::PUBLICATION_INDEX_KIND],
                'limit' => min(count($eventIds), self::MAX_RESULTS),
            ]), $eventsById, $eventsByCoordinate);
        }

        $authors = [];
        $identifiers = [];
        foreach ($references as $reference) {
            if ($reference['coordinate'] === null) {
                continue;
            }

            $parts = explode(':', $reference['coordinate'], 3);
            if (count($parts) !== 3 || (int) $parts[0] !== self::PUBLICATION_INDEX_KIND) {
                continue;
            }

            $authors[] = strtolower($parts[1]);
            $identifiers[] = $parts[2];
        }
        $authors = array_values(array_unique(array_filter($authors, fn (string $author): bool => preg_match('/^[a-f0-9]{64}$/', $author) === 1)));
        $identifiers = array_values(array_unique(array_filter($identifiers, static fn (string $identifier): bool => $identifier !== '')));

        if ($authors !== [] && $identifiers !== []) {
            $this->indexEvents($this->queryEvents([
                'authors' => $authors,
                'kinds' => [self::PUBLICATION_INDEX_KIND],
                '#d' => $identifiers,
                'limit' => self::MAX_RESULTS,
            ]), $eventsById, $eventsByCoordinate);
        }

        $books = [];
        $seen = [];
        foreach ($references as $reference) {
            $event = $reference['eventId'] !== null ? ($eventsById[$reference['eventId']] ?? null) : null;
            $event ??= $reference['coordinate'] !== null ? ($eventsByCoordinate[$reference['coordinate']] ?? null) : null;
            if ($event === null) {
                continue;
            }

            $book = $this->mapBook($event);
            if ($book === null || isset($seen[$book['coordinate']])) {
                continue;
            }

            $seen[$book['coordinate']] = true;
            $books[] = $book;
        }

        return $books;
    }

    /** @param array<string, mixed> $filter
     *  @return array<int, array<string, mixed>>
     */
    private function queryEvents(array $filter): array
    {
        $query = $this->eventQueryBuilder->filter(Nip01Filter::fromArray($filter));
        $hits = $this->booksIndex->search('bookshelf.my_books_fallback', $query);
        $events = [];
        foreach ($hits as $hit) {
            $event = $this->eventPresenter->present($hit['source'], $hit['id']);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @param array<string, array<string, mixed>> $eventsById
     * @param array<string, array<string, mixed>> $eventsByCoordinate
     */
    private function indexEvents(array $events, array &$eventsById, array &$eventsByCoordinate): void
    {
        foreach ($events as $event) {
            $book = $this->mapBook($event);
            if ($book === null) {
                continue;
            }

            $eventsById[$book['id']] = $event;
            $current = $eventsByCoordinate[$book['coordinate']] ?? null;
            if ($current === null || $book['createdAt'] > (int) ($current['created_at'] ?? 0)) {
                $eventsByCoordinate[$book['coordinate']] = $event;
            }
        }
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    private function mapBook(array $event): ?array
    {
        if ((int) ($event['kind'] ?? 0) !== self::PUBLICATION_INDEX_KIND) {
            return null;
        }

        $tags = is_array($event['tags'] ?? null) ? array_values(array_filter($event['tags'], 'is_array')) : [];
        $identifier = $this->firstTagValue($tags, 'd');
        $id = (string) ($event['id'] ?? '');
        $pubkey = (string) ($event['pubkey'] ?? '');
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
            'relay' => null,
            'createdAt' => (int) ($event['created_at'] ?? 0),
            'chapterCount' => $chapterCount,
        ];
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
