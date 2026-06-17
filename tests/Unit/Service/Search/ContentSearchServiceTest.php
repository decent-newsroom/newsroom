<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Search;

use App\Entity\Article;
use App\Service\Search\ArticleSearchInterface;
use App\Service\Search\ContentSearchService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ContentSearchServiceTest extends TestCase
{
    public function testFindRelatedArticlesExcludesCurrentArticleDeduplicatesAndHonorsLimit(): void
    {
        $articleSearch = $this->createMock(ArticleSearchInterface::class);
        $service = new ContentSearchService($articleSearch, new NullLogger());

        $current = (new Article())
            ->setPubkey('current-author')
            ->setSlug('current-slug')
            ->setTopics(['Nostr', 'nostr', ' Bitcoin ']);

        $articleSearch
            ->expects($this->once())
            ->method('findByTopics')
            ->with(['nostr', 'bitcoin'], 6, 0)
            ->willReturn([
                $this->article('current-author', 'current-slug'),
                $this->article('author-a', 'shared-slug'),
                $this->article('author-a', 'shared-slug'),
                $this->article('author-b', 'shared-slug'),
                $this->article('author-c', 'third-slug'),
                $this->article('author-d', 'fourth-slug'),
            ]);

        $related = $service->findRelatedArticles($current, limit: 3);

        self::assertSame(
            ['author-a:shared-slug', 'author-b:shared-slug', 'author-c:third-slug'],
            array_map(
                static fn (Article $article): string => $article->getPubkey() . ':' . $article->getSlug(),
                $related,
            )
        );
    }

    private function article(string $pubkey, string $slug): Article
    {
        return (new Article())
            ->setPubkey($pubkey)
            ->setSlug($slug);
    }
}

