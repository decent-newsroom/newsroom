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
