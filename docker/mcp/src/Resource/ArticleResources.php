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
        $article = $this->client->getArticle($coordinate);

        if ($article === null) {
            return ['error' => 'Article not found', 'coordinate' => $coordinate];
        }

        return $article;
    }
}
