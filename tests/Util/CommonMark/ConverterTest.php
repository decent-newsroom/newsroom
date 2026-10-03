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
