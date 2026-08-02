<?php

declare(strict_types=1);

namespace DecentNewsroom\Mcp\Tool;

use DecentNewsroom\Mcp\Client\NewsroomApiClient;
use PhpMcp\Server\Attributes\McpTool;

/**
 * Read-only MCP tools over the Decent Newsroom long-form article corpus.
 *
 * Each tool is a thin passthrough to the newsroom internal API; limits,
 * deduplication and draft exclusion are enforced server-side by the newsroom.
 */
class ArticleTools
{
    public function __construct(
        private readonly NewsroomApiClient $client,
    ) {
    }

    /**
     * Full-text search across Decent Newsroom articles (titles, summaries, content).
     *
     * @param string $query Search keywords or phrase.
     * @param int $limit Maximum number of results (1-50).
     * @param int $offset Pagination offset.
     * @return array<int, array<string, mixed>> Matching articles (metadata only, no full content).
     */
    #[McpTool(name: 'search_articles')]
    public function searchArticles(string $query, int $limit = 12, int $offset = 0): array
    {
        return $this->client->search($query, $limit, $offset);
    }

    /**
     * Fetch a single article, including its full content, by Nostr coordinate.
     *
     * @param string $coordinate Article coordinate in the form kind:pubkey:slug (e.g. 30023:<hex>:my-slug).
     * @return array<string, mixed> The article with full markdown content, or an error payload if not found.
     */
    #[McpTool(name: 'get_article')]
    public function getArticle(string $coordinate): array
    {
        $article = $this->client->getArticle($coordinate);

        if ($article === null) {
            return ['error' => 'Article not found', 'coordinate' => $coordinate];
        }

        return $article;
    }

    /**
     * List the most recently published Decent Newsroom articles.
     *
     * @param int $limit Maximum number of results (1-50).
     * @return array<int, array<string, mixed>> Latest articles (metadata only).
     */
    #[McpTool(name: 'list_latest')]
    public function listLatest(int $limit = 20): array
    {
        return $this->client->latest($limit);
    }

    /**
     * List articles written by a given author.
     *
     * @param string $author Author public key as hex or npub (bech32).
     * @param int $limit Maximum number of results (1-50).
     * @param int $offset Pagination offset.
     * @return array<int, array<string, mixed>> The author's articles (metadata only).
     */
    #[McpTool(name: 'list_by_author')]
    public function listByAuthor(string $author, int $limit = 12, int $offset = 0): array
    {
        return $this->client->byAuthor($author, $limit, $offset);
    }

    /**
     * List articles tagged with any of the given topics.
     *
     * @param array<int, string> $topics One or more topic/tag names (matched with OR).
     * @param int $limit Maximum number of results (1-50).
     * @param int $offset Pagination offset.
     * @return array<int, array<string, mixed>> Matching articles (metadata only).
     */
    #[McpTool(name: 'list_by_topic')]
    public function listByTopic(array $topics, int $limit = 12, int $offset = 0): array
    {
        return $this->client->byTopic(array_values($topics), $limit, $offset);
    }

    /**
     * Get article counts for one or more topics, useful for gauging topic popularity.
     *
     * @param array<int, string> $topics One or more topic/tag names.
     * @return array<string, int> Map of topic name to article count.
     */
    #[McpTool(name: 'list_topics')]
    public function listTopics(array $topics): array
    {
        return $this->client->topicCounts(array_values($topics));
    }
}
