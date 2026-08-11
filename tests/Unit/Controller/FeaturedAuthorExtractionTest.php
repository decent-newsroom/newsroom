<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\AuthorController;
use PHPUnit\Framework\TestCase;

/**
 * Covers the coordinate-based featured-author extraction used to detect which
 * authors a reading list / magazine features, and to inject 'p' tags at creation.
 */
class FeaturedAuthorExtractionTest extends TestCase
{
    private const A = '1111111111111111111111111111111111111111111111111111111111111111';
    private const B = '2222222222222222222222222222222222222222222222222222222222222222';

    public function testExtractsLongformAndDraftAuthors(): void
    {
        $tags = [
            ['d', 'my-list'],
            ['type', 'reading-list'],
            ['a', '30023:' . self::A . ':first-article'],
            ['a', '30024:' . self::B . ':draft-article'],
        ];

        self::assertSame(
            [self::A, self::B],
            AuthorController::extractFeaturedAuthorPubkeys($tags),
        );
    }

    public function testDeduplicatesRepeatedAuthors(): void
    {
        $tags = [
            ['a', '30023:' . self::A . ':one'],
            ['a', '30023:' . self::A . ':two'],
            ['a', '30023:' . self::B . ':three'],
        ];

        self::assertSame([self::A, self::B], AuthorController::extractFeaturedAuthorPubkeys($tags));
    }

    public function testExcludesOwnerPubkeyWhenRequested(): void
    {
        $tags = [
            ['a', '30023:' . self::A . ':one'],
            ['a', '30023:' . self::B . ':two'],
        ];

        self::assertSame([self::B], AuthorController::extractFeaturedAuthorPubkeys($tags, self::A));
    }

    public function testIgnoresNonArticleCoordinatesAndMalformedTags(): void
    {
        $tags = [
            ['a', '30040:' . self::A . ':nested-category'], // magazine category, not an article
            ['a', '10002:' . self::B . ':relays'],          // unrelated kind
            ['a', 'not-a-coordinate'],
            ['a'],                                            // missing value
            ['p', self::A],                                   // existing p tag, not an 'a'
            ['a', '30023:' . self::B . ':valid'],
        ];

        self::assertSame([self::B], AuthorController::extractFeaturedAuthorPubkeys($tags));
    }

    public function testReturnsEmptyWhenNoArticleCoordinates(): void
    {
        $tags = [
            ['d', 'empty-list'],
            ['type', 'reading-list'],
            ['title', 'Empty'],
        ];

        self::assertSame([], AuthorController::extractFeaturedAuthorPubkeys($tags));
    }
}
