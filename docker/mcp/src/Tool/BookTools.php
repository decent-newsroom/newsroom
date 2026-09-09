<?php

declare(strict_types=1);

namespace DecentNewsroom\Mcp\Tool;

use DecentNewsroom\Mcp\Client\BooksApiClient;
use PhpMcp\Server\Attributes\McpTool;

/**
 * Read-only MCP tools over the Decent Newsroom Books API.
 */
class BookTools
{
    public function __construct(
        private readonly BooksApiClient $client,
    ) {
    }

    /**
     * Search Nostr publication indexes (kind 30040) by text or structured metadata.
     *
     * @return array<int, array<string, mixed>> Matching Nostr publication events.
     */
    #[McpTool(name: 'search_books')]
    public function searchBooks(
        ?string $query = null,
        ?string $title = null,
        ?string $author = null,
        ?string $language = null,
        ?string $subject = null,
        ?string $d = null,
        ?string $identifier = null,
        int $limit = 25,
    ): array {
        return $this->client->searchPublications(array_filter([
            'q' => $query,
            'title' => $title,
            'author' => $author,
            'language' => $language,
            'subject' => $subject,
            'd' => $d,
            'identifier' => $identifier,
            'limit' => $limit,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * Search the full text of Nostr publication sections (kind 30041).
     *
     * @return array<int, array<string, mixed>> Matching Nostr section events.
     */
    #[McpTool(name: 'search_book_sections')]
    public function searchBookSections(string $query, int $limit = 25): array
    {
        return $this->client->searchSections($query, $limit);
    }

    /**
     * Fetch a book's publication index by its exact Nostr event ID.
     *
     * @return array<string, mixed> The book event, or an error payload if unavailable.
     */
    #[McpTool(name: 'get_book')]
    public function getBook(string $eventId): array
    {
        return $this->bookOrError($eventId);
    }

    /**
     * @return array<string, mixed>
     */
    private function bookOrError(string $eventId): array
    {
        $book = $this->client->getBook($eventId);
        if ($book === null) {
            return ['error' => 'Book not found', 'eventId' => $eventId];
        }
        if (($book['kind'] ?? null) !== 30040) {
            return ['error' => 'Event is not a book', 'eventId' => $eventId];
        }

        return $book;
    }
}
