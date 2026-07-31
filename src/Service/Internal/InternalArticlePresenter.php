<?php

declare(strict_types=1);

namespace App\Service\Internal;

use App\Entity\Article;
use App\Util\NostrKeyUtil;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Normalizes Article entities into a stable JSON-serializable shape for the
 * internal read-only API consumed by the standalone MCP service.
 *
 * This is the single source of truth for the article contract exposed to the
 * MCP layer. Keep fields additive so downstream consumers do not break.
 */
class InternalArticlePresenter
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Normalize a list of articles, deduplicating replaceable events by
     * coordinate (kind:pubkey:slug), keeping the newest revision.
     *
     * @param iterable<Article> $articles
     * @param bool $includeContent Whether to include the full content markdown.
     * @return array<int, array<string, mixed>>
     */
    public function presentMany(iterable $articles, bool $includeContent = false): array
    {
        $byCoordinate = [];

        foreach ($articles as $article) {
            $item = $this->present($article, $includeContent);
            if ($item === null) {
                continue;
            }

            $coordinate = $item['coordinate'];
            if (isset($byCoordinate[$coordinate])) {
                $existingTs = $byCoordinate[$coordinate]['_createdTs'] ?? 0;
                if (($item['_createdTs'] ?? 0) <= $existingTs) {
                    continue;
                }
            }

            $byCoordinate[$coordinate] = $item;
        }

        return array_values(array_map(
            static function (array $item): array {
                unset($item['_createdTs']);
                return $item;
            },
            $byCoordinate
        ));
    }

    /**
     * Normalize a single article into the internal contract shape.
     * Returns null when the article lacks the minimum required fields.
     *
     * @return array<string, mixed>|null
     */
    public function present(Article $article, bool $includeContent = false): ?array
    {
        $pubkey = $article->getPubkey();
        $slug = $article->getSlug();
        $title = $article->getTitle();

        if ($pubkey === null || $pubkey === '' || $slug === null || $slug === '' || $title === null || $title === '') {
            return null;
        }

        $kind = $article->getKind()?->value ?? 30023;
        $coordinate = $kind . ':' . $pubkey . ':' . $slug;

        $topics = $article->getTopics();
        if (!is_array($topics)) {
            $topics = [];
        }

        $data = [
            'coordinate' => $coordinate,
            'kind' => $kind,
            'title' => $title,
            'summary' => $article->getSummary() ?? '',
            'pubkey' => $pubkey,
            'npub' => $this->encodeNpub($pubkey),
            'slug' => $slug,
            'topics' => array_values($topics),
            'image' => $article->getImage(),
            'publishedAt' => $article->getPublishedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $article->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'url' => $this->buildUrl($pubkey, $slug),
            '_createdTs' => $article->getCreatedAt()?->getTimestamp() ?? 0,
        ];

        if ($includeContent) {
            $data['content'] = $article->getContent() ?? '';
        }

        return $data;
    }

    private function encodeNpub(string $pubkey): ?string
    {
        try {
            return NostrKeyUtil::hexToNpub($pubkey);
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildUrl(string $pubkey, string $slug): ?string
    {
        try {
            $npub = NostrKeyUtil::hexToNpub($pubkey);

            return $this->urlGenerator->generate(
                'author-article-slug',
                ['npub' => $npub, 'slug' => $slug],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
