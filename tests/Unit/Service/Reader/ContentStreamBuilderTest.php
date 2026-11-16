<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Reader;

use App\Entity\Article;
use App\Entity\Update;
use App\Entity\User;
use App\Enum\KindsEnum;
use App\Service\Reader\ContentStreamBuilder;
use PHPUnit\Framework\TestCase;

final class ContentStreamBuilderTest extends TestCase
{
    public function testAllSourcesAreInterleavedByEventDate(): void
    {
        $rows = (new ContentStreamBuilder())->build([
            ['type' => 'article', 'items' => [(object) ['pubkey' => 'a', 'slug' => 'article', 'createdAt' => '2026-11-15T10:00:00+00:00']]],
            ['type' => 'media', 'items' => [(object) ['id' => 'media', 'created_at' => strtotime('2026-11-15T10:01:00+00:00')]]],
            ['type' => 'highlight', 'items' => [['id' => 'highlight', 'created_at' => strtotime('2026-11-15T09:59:00+00:00')]]],
            ['type' => 'reading_list', 'items' => [['pubkey' => 'a', 'slug' => 'list']]],
        ]);
        self::assertSame(['media', 'article', 'highlight', 'reading_list'], array_column($rows, 'type'));
        self::assertSame(0, $rows[3]['timestamp']);
    }

    public function testRepeatedArticlesKeepNewestRevisionAndEverySourceLabel(): void
    {
        $rows = (new ContentStreamBuilder())->build([
            ['type' => 'article', 'items' => [['pubkey' => 'a', 'slug' => 'same', 'title' => 'Old', 'createdAt' => 100]], 'label' => 'featured'],
            ['type' => 'article', 'items' => [['pubkey' => 'a', 'slug' => 'same', 'title' => 'New', 'createdAt' => 200]], 'label' => 'pack'],
            ['type' => 'article', 'items' => [['pubkey' => 'a', 'slug' => 'same', 'title' => 'Old', 'createdAt' => 100]], 'label' => 'featured'],
        ]);
        self::assertCount(1, $rows);
        self::assertSame('New', $rows[0]['item']['title']);
        self::assertSame(['featured', 'pack'], $rows[0]['labels']);
    }

    public function testDraftsAuthorsAndCaseSensitiveIdentifiersStayDistinct(): void
    {
        $rows = (new ContentStreamBuilder())->build([
            ['type' => 'article', 'items' => [
                ['pubkey' => 'a', 'slug' => 'Case', 'createdAt' => 1],
                ['pubkey' => 'a', 'slug' => 'case', 'createdAt' => 1],
                ['pubkey' => 'b', 'slug' => 'Case', 'createdAt' => 1],
            ]],
            ['type' => 'draft', 'items' => [['pubkey' => 'a', 'slug' => 'Case', 'createdAt' => 1]]],
        ]);
        self::assertCount(4, $rows);
        self::assertCount(4, array_unique(array_column($rows, 'key')));
    }

    public function testHighlightsInActivityAreNotRepeatedAndEqualTimeOrderIsStable(): void
    {
        $sources = [
            ['type' => 'activity', 'items' => [['type' => 'highlight', 'id' => 'b', 'created_at' => 100]]],
            ['type' => 'highlight', 'items' => [['id' => 'b', 'created_at' => 100], ['id' => 'a', 'created_at' => 100]]],
        ];
        $builder = new ContentStreamBuilder();
        self::assertSame(['highlight:a', 'highlight:b'], array_column($builder->build($sources), 'key'));
        self::assertSame(array_column($builder->build($sources), 'key'), array_column($builder->build(array_reverse($sources)), 'key'));
    }

    public function testEntityDatesAndLocalSubscriptionRecordsAreSupportedWithoutMutation(): void
    {
        $article = new Article();
        $article->setPubkey(str_repeat('a', 64));
        $article->setSlug('essay');
        $article->setKind(KindsEnum::LONGFORM);
        $article->setCreatedAt(new \DateTimeImmutable('@100'));
        $update = new Update(new User(), str_repeat('b', 64), 30040, str_repeat('a', 64), '/publication', new \DateTimeImmutable('@200'));
        $rows = (new ContentStreamBuilder())->build([
            ['type' => 'article', 'items' => [$article]],
            ['type' => 'update', 'items' => [$update]],
        ]);
        self::assertSame(['update', 'article'], array_column($rows, 'type'));
        self::assertNull($update->getSeenAt());
        self::assertNull($update->getReadAt());
    }

    public function testInvalidSourceDatesAreNotSilentlyPresentedAsNewContent(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new ContentStreamBuilder())->build([
            ['type' => 'media', 'items' => [['id' => 'a', 'created_at' => 'not a date']]],
        ]);
    }

    public function testMixedPaginationDoesNotTruncateASourceAtTwentyFourMediaItems(): void
    {
        $media = [];
        for ($i = 1; $i <= 200; ++$i) {
            $media[] = ['id' => (string) $i, 'created_at' => $i];
        }
        $rows = (new ContentStreamBuilder())->build([['type' => 'media', 'items' => $media]]);
        self::assertCount(200, $rows);
        self::assertSame('media:200', $rows[0]['key']);
        self::assertSame('media:1', $rows[199]['key']);
    }
}
