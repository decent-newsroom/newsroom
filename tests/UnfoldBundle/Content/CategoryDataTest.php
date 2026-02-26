<?php

declare(strict_types=1);

namespace App\Tests\UnfoldBundle\Content;

use App\UnfoldBundle\Content\CategoryData;
use PHPUnit\Framework\TestCase;

final class CategoryDataTest extends TestCase
{
    public function testFromEventParsesSummaryFromTag(): void
    {
        $event = (object) [
            'tags' => [
                ['d', 'my-category'],
                ['title', 'My Category'],
                ['summary', 'A short summary'],
                ['a', '30023:pubkey:article-1'],
            ],
            'content' => '',
        ];

        $cat = CategoryData::fromEvent($event, '30040:pubkey:my-category');

        self::assertSame('my-category', $cat->slug);
        self::assertSame('My Category', $cat->title);
        self::assertSame('A short summary', $cat->summary);
        self::assertSame('30040:pubkey:my-category', $cat->coordinate);
        self::assertSame(['30023:pubkey:article-1'], $cat->articleCoordinates);
    }

    public function testFromEventParsesSummaryFromJsonContentFallback(): void
    {
        $event = (object) [
            'tags' => [
                ['d', 'my-category'],
                ['title', ''],
            ],
            'content' => json_encode([
                'title' => 'Title in JSON',
                'description' => 'Summary in JSON',
            ], JSON_THROW_ON_ERROR),
        ];

        $cat = CategoryData::fromEvent($event, '30040:pubkey:my-category');

        self::assertSame('Title in JSON', $cat->title);
        self::assertSame('Summary in JSON', $cat->summary);
    }
}

