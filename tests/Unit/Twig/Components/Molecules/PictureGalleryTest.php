<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components\Molecules;

use App\Twig\Components\Molecules\PictureGallery;
use PHPUnit\Framework\TestCase;

final class PictureGalleryTest extends TestCase
{
    public function testImagesPreserveSignedOrderAndPerImageMetadata(): void
    {
        $gallery = new PictureGallery();
        $gallery->mount([
            ['alt', 'Global description'],
            ['imeta', 'url https://example.com/first.png', 'alt First picture', 'dim 1200x800',
                'fallback https://example.com/backup.png', 'fallback javascript:alert(1)',
                'annotate-user ' . str_repeat('a', 64) . ':25:50'],
            ['imeta', 'url https://example.com/second.png', 'blurhash abc'],
            ['imeta', 'url https://example.com/first.png'],
        ], 'Caption mentioning https://example.com/first.png', '/e/exact');

        self::assertSame([
            'https://example.com/first.png', 'https://example.com/second.png', 'https://example.com/first.png',
        ], array_column($gallery->images, 'url'));
        self::assertSame('First picture', $gallery->images[0]['alt']);
        self::assertSame('Global description', $gallery->images[1]['alt']);
        self::assertSame('1200x800', $gallery->images[0]['dimensions']);
        self::assertSame(['https://example.com/backup.png'], $gallery->images[0]['fallbacks']);
        self::assertSame([['ident' => str_repeat('a', 64), 'x' => 25.0, 'y' => 50.0]], $gallery->images[0]['annotations']);
        self::assertSame('abc', $gallery->images[1]['blurhash']);
        self::assertSame('/e/exact', $gallery->eventUrl);
        self::assertFalse($gallery->sensitive);
    }

    public function testMalformedAndNonImageMetadataDoesNotCreateBrokenGalleryItems(): void
    {
        $gallery = new PictureGallery();
        $gallery->mount([
            'bad', [], ['imeta'], ['imeta', ['url', 'https://example.com/not-a-string.png']],
            ['imeta', 'url javascript:alert(1)'],
            ['imeta', 'url https://example.com/movie.mp4', 'm video/mp4'],
            ['imeta', 'url https://example.com/image.png', 'annotate-user invalid:1:2',
                'annotate-user ' . str_repeat('a', 64) . ':101:2'],
        ]);
        self::assertCount(1, $gallery->images);
        self::assertSame([], $gallery->images[0]['annotations']);
    }

    public function testEmptyContentWarningAndSensitiveLabelsGateGallery(): void
    {
        foreach ([[['content-warning']], [['l', 'adult', 'nsfw']], [['t', 'NSFW']]] as $tags) {
            $gallery = new PictureGallery();
            $gallery->mount($tags);
            self::assertTrue($gallery->sensitive);
            self::assertNull($gallery->warning);
        }
    }
}
