<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Magazine;

use App\Service\Magazine\PublicationIndexClassifier;
use PHPUnit\Framework\TestCase;

final class PublicationIndexClassifierTest extends TestCase
{
    /**
     * @dataProvider bookTagsProvider
     */
    public function testItClassifiesLibraryCardsAndChapterIndexesAsBooks(array $tags, bool $expected): void
    {
        self::assertSame($expected, (new PublicationIndexClassifier())->isBook($tags));
    }

    public static function bookTagsProvider(): iterable
    {
        yield 'library card without relationships' => [
            [['d', 'library-card'], ['title', 'A book'], ['i', 'isbn:9780000000000']],
            true,
        ];
        yield 'index with 30041 chapter' => [
            [['d', 'book'], ['a', '30041:' . str_repeat('a', 64) . ':chapter-1']],
            true,
        ];
        yield 'magazine with 30040 section' => [
            [['d', 'magazine'], ['a', '30040:' . str_repeat('a', 64) . ':section-1']],
            false,
        ];
        yield 'index with event relationship' => [
            [['d', 'external'], ['e', str_repeat('b', 64)]],
            false,
        ];
    }
}
