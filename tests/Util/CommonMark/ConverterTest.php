<?php

namespace App\Tests\Util\CommonMark;

use App\Factory\ArticleFactory;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use AsciiDocConverter;
use App\Util\CommonMark\Converter;
use PHPUnit\Framework\TestCase;
use Twig\Environment as TwigEnvironment;

class ConverterTest extends TestCase
{
    public function testGalleryCaptionsKeepImageMentionsAsLinksWithoutChangingDefaultRendering(): void
    {
        $converter = new Converter(
            $this->createMock(RedisCacheService::class),
            $this->createMock(TwigEnvironment::class),
            $this->createMock(ArticleFactory::class),
            new AsciiDocConverter(),
            $this->createMock(EventRepository::class),
        );
        $source = "A **photo** mentioned here: https://example.com/photo.jpg\n\n"
            . "![An **important** photo](https://example.com/another.png)\n\n"
            . "[Photo link](https://example.com/photo.webp)\n\n"
            . "https://images.nostria.app/extensionless-photo\n\n"
            . "https://i.nostr.build/example.png\n\n"
            . "[![Linked photo](https://example.com/inside.png)](https://example.com/album)\n\n"
            . "[Unsafe](javascript:alert%281%29)\n\n"
            . '<img src="https://example.com/raw.jpg">';
        $html = $converter->convertToHTML($source, renderMedia: false);
        $crawler = new \Symfony\Component\DomCrawler\Crawler($html);

        self::assertCount(0, $crawler->filter('img, picture, video, iframe, .embedded-content'));
        self::assertSame([
            'https://example.com/photo.jpg', 'https://example.com/another.png',
            'https://example.com/photo.webp', 'https://images.nostria.app/extensionless-photo',
            'https://i.nostr.build/example.png', 'https://example.com/album',
        ], $crawler->filter('a[href]')->extract(['href']));
        self::assertSame('An important photo', $crawler->filter('a')->eq(1)->text());
        self::assertStringContainsString('<strong>photo</strong>', $html);
        self::assertStringContainsString('&lt;img', $html);

        $normal = $converter->convertToHTML('![Photo](https://example.com/photo.jpg)');
        self::assertStringContainsString('<img', $normal);
    }

    /** @dataProvider explicitContentKinds */
    public function testContentKindsSelectTheirFormatWithoutSniffing(int $kind, string $format): void
    {
        $converter = new Converter(
            $this->createMock(RedisCacheService::class),
            $this->createMock(TwigEnvironment::class),
            $this->createMock(ArticleFactory::class),
            new AsciiDocConverter(),
            $this->createMock(EventRepository::class),
        );
        $source = "= Heading\n\n**Ambiguous** text";

        self::assertSame(
            $converter->convertToHTML($source, $format),
            $converter->convertToHTML($source, null, $kind),
        );
    }

    public static function explicitContentKinds(): array
    {
        return [[30023, 'markdown'], [30041, 'asciidoc'], [30818, 'asciidoc'], [30817, 'markdown']];
    }

    public function testSingleNewlineBecomesHtmlLineBreakInsideParagraph(): void
    {
        $converter = new Converter(
            $this->createMock(RedisCacheService::class),
            $this->createMock(TwigEnvironment::class),
            $this->createMock(ArticleFactory::class),
            new AsciiDocConverter(),
            $this->createMock(EventRepository::class),
        );

        $html = $converter->convertToHTML("first line\nsecond line", 'markdown');

        $this->assertStringContainsString('<p>first line<br />', $html);
        $this->assertStringContainsString("\nsecond line</p>", $html);
    }
}
