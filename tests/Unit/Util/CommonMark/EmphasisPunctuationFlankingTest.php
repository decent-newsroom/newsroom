<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util\CommonMark;

use App\Service\Cache\RedisCacheService;
use App\Util\CommonMark\Converter;
use App\Util\NostrKeyUtil;
use PHPUnit\Framework\TestCase;

/**
 * CommonMark flanking delimiter edge case: bold/italic wrapping a single
 * punctuation character immediately followed by a word character
 * (e.g. **#**livingmen) requires a zero-width space so the closing
 * delimiter qualifies as right-flanking.
 */
class EmphasisPunctuationFlankingTest extends TestCase
{
    private object $converter;
    private \ReflectionMethod $convertMarkdownToHTML;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(Converter::class);
        $this->converter = $ref->newInstanceWithoutConstructor();

        // Initialize required properties to prevent access-before-init errors
        $mathProp = $ref->getProperty('mathPlaceholders');
        $mathProp->setValue($this->converter, []);

        $redisProp = $ref->getProperty('redisCacheService');
        $redisProp->setValue($this->converter, $this->createMock(RedisCacheService::class));

        $keyProp = $ref->getProperty('nostrKeyUtil');
        $keyProp->setValue($this->converter, $this->createMock(NostrKeyUtil::class));

        $prefetchProp = $ref->getProperty('prefetchedData');
        $prefetchProp->setValue($this->converter, null);

        $this->convertMarkdownToHTML = $ref->getMethod('convertMarkdownToHTML');
    }

    private function toHtml(string $markdown): string
    {
        return $this->convertMarkdownToHTML->invoke($this->converter, $markdown);
    }

    public function testBoldHashFollowedByWord(): void
    {
        $html = $this->toHtml('**#**livingmen lebt im Werden.');
        $this->assertStringContainsString('<strong>#</strong>', $html);
    }

    public function testBoldHashStandalone(): void
    {
        // When followed by space, CommonMark handles it natively.
        $html = $this->toHtml('**#** livingmen lebt im Werden.');
        $this->assertStringContainsString('<strong>#</strong>', $html);
    }

    public function testItalicHashFollowedByWord(): void
    {
        $html = $this->toHtml('*#*livingmen lebt im Werden.');
        $this->assertStringContainsString('<em>#</em>', $html);
    }

    public function testBoldExclamationFollowedByWord(): void
    {
        $html = $this->toHtml('**!**important notice');
        $this->assertStringContainsString('<strong>!</strong>', $html);
    }

    public function testBoldLetterFollowedByWordStillWorks(): void
    {
        // Non-punctuation content should still work (no regression).
        $html = $this->toHtml('**A**livingmen lebt im Werden.');
        $this->assertStringContainsString('<strong>A</strong>', $html);
    }

    public function testNormalBoldUnaffected(): void
    {
        // Standard bold should not be affected.
        $html = $this->toHtml('This is **bold text** in a sentence.');
        $this->assertStringContainsString('<strong>bold text</strong>', $html);
    }
}


