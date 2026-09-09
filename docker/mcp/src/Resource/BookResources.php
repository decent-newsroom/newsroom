<?php

declare(strict_types=1);

namespace DecentNewsroom\Mcp\Resource;

use DecentNewsroom\Mcp\Client\BooksApiClient;
use PhpMcp\Server\Attributes\McpResourceTemplate;

/**
 * Exposes publication indexes as MCP resources by their Nostr event ID.
 */
class BookResources
{
    public function __construct(
        private readonly BooksApiClient $client,
    ) {
    }

    /**
     * @return array<string, mixed> The book event, or an error payload if unavailable.
     */
    #[McpResourceTemplate(uriTemplate: 'dn://book/{eventId}', mimeType: 'application/json')]
    public function book(string $eventId): array
    {
        $eventId = rawurldecode($eventId);
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
