<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Entity\Article;
use App\Service\Nostr\NostrClient;
use App\Service\Search\ArticleSearchInterface;
use App\Twig\Components\Organisms\RelatedArticles;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;

final class RelatedArticlesTest extends TestCase
{
    public function testMountDeduplicatesByPubkeyAndSlugAndExcludesCurrentArticle(): void
    {
        $articleSearch = $this->createMock(ArticleSearchInterface::class);
        $nostrClient = $this->createMock(NostrClient::class);
        $security = $this->createMock(Security::class);
        $logger = $this->createMock(LoggerInterface::class);

        $security->method('getUser')->willReturn(null);

        $nostrClient
            ->expects($this->never())
            ->method('getUserInterests');

        $articleSearch
            ->expects($this->once())
            ->method('findByTopics')
            ->with(['nostr'], 8)
            ->willReturn([
                $this->article('current-author', 'current-slug'),
                $this->article('author-a', 'shared-slug'),
                $this->article('author-a', 'shared-slug'), // duplicate coordinate, should be skipped
                $this->article('author-b', 'shared-slug'), // same slug, different author => allowed
                $this->article('author-c', 'third-slug'),
                $this->article('author-d', 'fourth-slug'), // beyond MAX_RESULTS after filtering
            ]);

        $component = new RelatedArticles($articleSearch, $nostrClient, $security, $logger);
        $component->mount('30023:current-author:current-slug', ['Nostr'], 'current-author');

        $this->assertSame(false, $component->fromInterests);
        $this->assertCount(3, $component->articles);
        $this->assertSame(
            ['author-a:shared-slug', 'author-b:shared-slug', 'author-c:third-slug'],
            array_map(
                static fn (Article $article): string => $article->getPubkey() . ':' . $article->getSlug(),
                $component->articles
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

