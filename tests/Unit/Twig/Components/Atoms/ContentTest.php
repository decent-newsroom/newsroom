<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components\Atoms;

use App\Twig\Components\Atoms\Content;
use App\Util\CommonMark\Converter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ContentTest extends TestCase
{
    public function testGalleryModeIsPassedToConverter(): void
    {
        $converter = $this->createMock(Converter::class);
        $converter->expects(self::once())->method('convertToHTML')
            ->with('Caption', null, null, null, false)->willReturn('<p>Caption</p>');
        $content = new Content($converter, $this->createMock(LoggerInterface::class));
        $content->mount('Caption', false);
        self::assertSame('<p>Caption</p>', $content->parsed);
    }

    public function testConversionFailureIsLoggedAndPreservesEscapedText(): void
    {
        $converter = $this->createMock(Converter::class);
        $exception = new \RuntimeException('Conversion failed');
        $converter->method('convertToHTML')->willThrowException($exception);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('Content rendering failed.', ['exception' => $exception]);
        $content = new Content($converter, $logger);
        $content->mount('<img src=x onerror=alert(1)>', false);
        self::assertSame('&lt;img src=x onerror=alert(1)&gt;', $content->parsed);
    }
}
