<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util\CommonMark;

use PHPUnit\Framework\TestCase;

/**
 * Regression test: emphasis markers with HTML-encoded whitespace (&#x20;)
 * must be normalised correctly so CommonMark renders them as emphasis.
 */
class EmphasisHtmlEntitiesTest extends TestCase
{
    private \ReflectionMethod $normalizeEmphasisWhitespace;
    private object $converter;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(\App\Util\CommonMark\Converter::class);
        $this->converter = $ref->newInstanceWithoutConstructor();

        $this->normalizeEmphasisWhitespace = $ref->getMethod('normalizeEmphasisWhitespace');
        $this->normalizeEmphasisWhitespace->setAccessible(true);
    }

    private function normalize(string $content): string
    {
        return $this->normalizeEmphasisWhitespace->invoke($this->converter, $content);
    }

    public function testEmphasisWithTrailingSpaceIsRelocated(): void
    {
        // After html_entity_decode, &#x20; becomes a real space.
        // The normaliser must move that trailing space outside the delimiter.
        $input = '*What we are seeing in real time. *';
        $expected = '*What we are seeing in real time.* ';

        $this->assertSame($expected, $this->normalize($input));
    }

    public function testBoldWithTrailingSpaceIsRelocated(): void
    {
        $input = '**Here is a link **';
        $expected = '**Here is a link** ';

        $this->assertSame($expected, $this->normalize($input));
    }

    public function testOverzealousEmphasisFullSample(): void
    {
        // The full sample after html_entity_decode (&#x20; → space, escaped brackets intact)
        $input = '\\\\[*What we are seeing in real time is the control of science and media by external forces. AI will resolve this, but it will take a while. I will write while I still can. *[**Here is a link **](https://zenodo.org/communities/redactedscience/)*to all of my published works on Zenodo.*]';

        $result = $this->normalize($input);

        // Bold trailing space relocated
        $this->assertStringContainsString('**Here is a link**', $result);
        // Italic trailing space relocated (first span)
        $this->assertStringContainsString('still can.*', $result);
        // Last italic span unchanged (no interior whitespace)
        $this->assertStringContainsString('*to all of my published works on Zenodo.*', $result);
    }
}

