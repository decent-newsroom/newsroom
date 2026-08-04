<?php

declare(strict_types=1);

namespace DecentNewsroom\Mcp\Resource;

use DecentNewsroom\Mcp\Client\NewsroomApiClient;
use PhpMcp\Server\Attributes\McpResourceTemplate;

/**
 * Exposes individual articles as MCP resources so clients can attach them as
 * context by URI: dn://article/{coordinate}.
 */
class ArticleResources
{
    public function __construct(
        private readonly NewsroomApiClient $client,
    ) {
    }

    /**
     * Resolve a single article (with full content) by its coordinate.
     *
     * @param string $coordinate Article coordinate in the form kind:pubkey:slug.
     * @return array<string, mixed> The article payload, or an error payload if not found.
     */
    #[McpResourceTemplate(uriTemplate: 'dn://article/{coordinate}', mimeType: 'application/json')]
    public function article(string $coordinate): array
    {
        // The URI template variable arrives percent-encoded (e.g. `30023%3A…`),
        // so the colons that separate kind:pubkey:slug must be restored before
        // the newsroom internal API can parse the coordinate. Decoding is
        // idempotent for a well-formed coordinate, which contains no `%`.
        $coordinate = rawurldecode($coordinate);

        $article = $this->client->getArticle($coordinate);

        if ($article === null) {
            return ['error' => 'Article not found', 'coordinate' => $coordinate];
        }

        return $article;
    }
}
