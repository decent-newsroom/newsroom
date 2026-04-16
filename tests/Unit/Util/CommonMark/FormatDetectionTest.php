<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util\CommonMark;

use PHPUnit\Framework\TestCase;

class FormatDetectionTest extends TestCase
{
    private object $converter;
    private \ReflectionMethod $isAsciiDoc;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(\App\Util\CommonMark\Converter::class);
        $this->converter = $ref->newInstanceWithoutConstructor();

        $this->isAsciiDoc = $ref->getMethod('isAsciiDoc');
        $this->isAsciiDoc->setAccessible(true);
    }

    private function detect(string $content): bool
    {
        return $this->isAsciiDoc->invoke($this->converter, $content);
    }

    public function testUserReportedMarkdownArticleWithDashSeparatorsIsNotDetectedAsAsciiDoc(): void
    {
        $content = <<<'MARKDOWN'
## It seems like weeks has passed, perhaps the war was an accomplice, so I'm sharing more links
### legend
    new client #vibe
    client #update
    protocol #groups #update #onboard
    Back end Devs stuff

# Group, community

----

# Onboarding tool
## gitgrasp
### A simple protocol for code collaboration that uses interoperable servers and clients
[gitgrasp website](https://gitgrasp.com/)

-----
# Client

1.  ## Emojiverse, emoji pack
    [website emojiverse | shakespeare](https://emojiverse.shakespeare.wtf/)
MARKDOWN;

        $this->assertFalse(
            $this->detect($content),
            'Markdown headings, ordered lists, and [text](url) links should keep this article in Markdown mode even when it contains ---- separators.',
        );
    }

    public function testSingleAsciiDocStyleLiteralDelimiterDoesNotForceAsciiDocMode(): void
    {
        $content = <<<'MARKDOWN'
# Heading

Paragraph before a separator.

----

More markdown content.
MARKDOWN;

        $this->assertFalse($this->detect($content));
    }

    public function testPairedAsciiDocLiteralBlockStillDetectsAsciiDoc(): void
    {
        $content = <<<'ASCIIDOC'
A short intro paragraph.

----
literal block
----
ASCIIDOC;

        $this->assertTrue($this->detect($content));
    }

    public function testAsciiDocDocumentTitleStillDetectsAsciiDoc(): void
    {
        $content = <<<'ASCIIDOC'
= My AsciiDoc Page

A paragraph.
ASCIIDOC;

        $this->assertTrue($this->detect($content));
    }
}

