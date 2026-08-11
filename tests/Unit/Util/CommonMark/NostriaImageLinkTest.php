<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util\CommonMark;

use App\Service\Cache\RedisCacheService;
use App\Util\CommonMark\Converter;
use PHPUnit\Framework\TestCase;

class NostriaImageLinkTest extends TestCase
{
    private object $converter;
    private \ReflectionMethod $convertMarkdownToHTML;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(Converter::class);
        $this->converter = $ref->newInstanceWithoutConstructor();

        $mathProp = $ref->getProperty('mathPlaceholders');
        $mathProp->setValue($this->converter, []);

        $redisProp = $ref->getProperty('redisCacheService');
        $redisProp->setValue($this->converter, $this->createMock(RedisCacheService::class));

        $prefetchProp = $ref->getProperty('prefetchedData');
        $prefetchProp->setValue($this->converter, null);

        $this->convertMarkdownToHTML = $ref->getMethod('convertMarkdownToHTML');
    }

    private function toHtml(string $markdown): string
    {
        return $this->convertMarkdownToHTML->invoke($this->converter, $markdown);
    }

    public function testMarkdownLinkToNostriaSubdomainIsRenderedAsImage(): void
    {
        $url = 'https://milo.nostria.app/4de37ab609fe9f2484a0e7e87e4a86b1140f508d0a0ebecceda63ccade305e24.webp';

        $html = $this->toHtml(sprintf('[%s](%s)', $url, $url));

        $this->assertStringContainsString('<img src="' . $url . '"', $html);
        $this->assertStringNotContainsString('<a href="' . $url . '"', $html);
    }

    public function testRawNostriaSubdomainUrlWithoutImageExtensionIsRenderedAsImage(): void
    {
        $url = 'https://milo.nostria.app/4de37ab609fe9f2484a0e7e87e4a86b1140f508d0a0ebecceda63ccade305e24';

        $html = $this->toHtml($url);

        $this->assertStringContainsString('<img src="' . $url . '"', $html);
        $this->assertStringNotContainsString('<a href="' . $url . '"', $html);
    }

    public function testNostriaApexHostIsNotRenderedAsWildcardImage(): void
    {
        $url = 'https://nostria.app/example.webp';

        $html = $this->toHtml(sprintf('[%s](%s)', $url, $url));

        $this->assertStringContainsString('<a href="' . $url . '">', $html);
        $this->assertStringNotContainsString('<img src="' . $url . '"', $html);
    }
}
