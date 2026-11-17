<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bookshelf;

use App\Bookshelf\BookshelfTocArticles;
use App\Entity\Event;
use App\Service\EventWorkspace\DriveMountEventAccessInterface;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use PHPUnit\Framework\TestCase;

final class BookshelfTocArticlesTest extends TestCase
{
    public function testResolvesOnlyVisibleValidArticlesIncludingFeaturedAndDuplicates(): void
    {
        $author = str_repeat('b', 64);
        $eventId = str_repeat('c', 64);
        $references = (new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()))
            ->fromAuthorizedFields(str_repeat('a', 64), 30040, $author, 123, [
                ['a', '30023:' . $author . ':article'],
                ['a', '30023:' . $author . ':article', '', $eventId],
                ['a', '30023:' . $author . ':article', '', 'bad-pin'],
                ['a', '30040:' . $author . ':index'],
            ], '')->references;
        $entry = static fn ($reference, bool $invalid = false): array => [
            'reference' => $reference, 'invalidVersionPin' => $invalid,
            'targetResolution' => null, 'target' => null,
        ];
        $article = new Event();
        $article->setId($eventId);
        $article->setTags([
            ['title', 'Article <title>'], ['summary', str_repeat('s', 510)], ['image', 'private image'],
        ]);
        $article->setContent('article body');
        $access = $this->createMock(DriveMountEventAccessInterface::class);
        $access->expects(self::once())->method('readableArticleTargets')
            ->with([$references[0], $references[1]])
            ->willReturn([0 => $article, 1 => $article]);
        $page = [
            'entries' => [$entry($references[0]), $entry($references[2], true), $entry($references[3])],
            'featuredEntry' => $entry($references[1]),
        ];

        $result = (new BookshelfTocArticles($access))->enrich($page);

        self::assertSame('available', $result['entries'][0]['targetResolution']);
        self::assertSame('Article <title>', $result['featuredEntry']['target']['title']);
        self::assertSame(str_repeat('s', 500), $result['featuredEntry']['target']['summary']);
        self::assertNull($result['entries'][1]['target']);
        self::assertNull($result['entries'][2]['target']);
        self::assertStringNotContainsString('article body', serialize($result));
        self::assertStringNotContainsString('private image', serialize($result));
    }

    public function testDeniedOrMissingTargetsRetainReferenceWithoutMetadata(): void
    {
        $reference = (new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()))
            ->fromAuthorizedFields(str_repeat('a', 64), 30040, str_repeat('b', 64), 123, [
                ['a', '30023:' . str_repeat('b', 64) . ':article'],
            ], '')->references[0];
        $entry = ['reference' => $reference, 'invalidVersionPin' => false, 'targetResolution' => null, 'target' => null];
        $access = $this->createMock(DriveMountEventAccessInterface::class);
        $access->expects(self::once())->method('readableArticleTargets')->willReturn([]);
        $page = ['entries' => [$entry], 'featuredEntry' => null];

        self::assertSame($page, (new BookshelfTocArticles($access))->enrich($page));
    }
}
