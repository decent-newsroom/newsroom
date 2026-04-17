<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util\CommonMark;

use PHPUnit\Framework\TestCase;

/**
 * Regression test: some Nostr clients publish content with backslash-escaped
 * markdown punctuation (\*\*, \#\#, \-, \[, etc.) and <br /> line separators.
 * The converter must detect this pattern and unescape before parsing.
 */
class OverEscapedMarkdownTest extends TestCase
{
    private \ReflectionMethod $unescapeMethod;
    private object $converter;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(\App\Util\CommonMark\Converter::class);
        $this->converter = $ref->newInstanceWithoutConstructor();

        $this->unescapeMethod = $ref->getMethod('unescapeOverEscapedMarkdown');
    }

    private function unescape(string $content): string
    {
        return $this->unescapeMethod->invoke($this->converter, $content);
    }

    public function testBoldIsUnescaped(): void
    {
        $input = '\\*\\*Onyx\\*\\* is a note-taking app.\\- item one\\- item two\\- item three\\#\\# Heading\\[link\\]\\(url\\)\\| table \\| row \\|';
        $result = $this->unescape($input);

        $this->assertStringContainsString('**Onyx**', $result);
        $this->assertStringContainsString('- item one', $result);
        $this->assertStringContainsString('## Heading', $result);
        $this->assertStringContainsString('[link](url)', $result);
        $this->assertStringContainsString('| table | row |', $result);
    }

    public function testBrTagsConvertedToNewlines(): void
    {
        $input = 'Paragraph one.<br />\\*\\*Bold\\*\\* paragraph two.<br/>Third \\- item\\- another\\- more\\#\\# Heading\\[a\\]\\(b\\)';
        $result = $this->unescape($input);

        $this->assertStringNotContainsString('<br', $result);
        $this->assertStringContainsString("Paragraph one.\n\n", $result);
    }

    public function testExcessiveBlankLinesCollapsed(): void
    {
        $input = 'One\\*\\*bold\\*\\*<br /><br /><br />Two\\- a\\- b\\- c\\- d\\- e\\#\\# H\\[x\\]\\(y\\)';
        $result = $this->unescape($input);

        $this->assertDoesNotMatchRegularExpression('/\n{3,}/', $result);
    }

    public function testBelowThresholdNotUnescaped(): void
    {
        // Only a few escapes — should NOT be modified.
        $input = 'Hello \\*world\\*. Normal text.';
        $result = $this->unescape($input);

        $this->assertSame($input, $result);
    }

    public function testRealWorldOnyxArticle(): void
    {
        // Excerpt from the real-world over-escaped article content.
        // In the actual Nostr event JSON, backslashes are single-escaped,
        // so the PHP string must contain literal \* sequences.
        $input = "I started a new career path.\n\n<br />\n\n"
            . "\\*\\*Onyx\\*\\* is a private, encrypted note-taking app with Nostr sync.\n\n<br />\n\n"
            . "\\---\n\n<br />\n\n"
            . "\\## Features\n\n<br />\n\n"
            . "\\### Core\n\n<br />\n\n"
            . "\\- \\*\\*Markdown Editor\\*\\* \xE2\x80\x94 Write notes with full markdown support\n"
            . "\\- \\*\\*Local-First\\*\\* \xE2\x80\x94 Your notes are stored locally\n"
            . "\\- \\*\\*Nostr Sync\\*\\* \xE2\x80\x94 Encrypted sync across devices\n\n<br />\n\n"
            . "\\### Publishing\n\n<br />\n\n"
            . "\\- \\*\\*Publish as Articles\\*\\* \xE2\x80\x94 Post markdown notes as NIP-23 long-form articles\n\n<br />\n\n"
            . "\\| Kind | Purpose | Encryption |\n"
            . "\\|------|---------|------------|\n"
            . "\\| 30800 | File content | NIP-44 (self) |\n\n<br />\n\n"
            . "\\*\\*GitHub Repository:\\*\\* \\[https\\://github.com/derekross/onyx]\\(https\\://github.com/derekross/onyx)";

        $result = $this->unescape($input);

        // Headings should be real markdown
        $this->assertStringContainsString('## Features', $result);
        $this->assertStringContainsString('### Core', $result);

        // Bold should work
        $this->assertStringContainsString('**Onyx**', $result);
        $this->assertStringContainsString('**Markdown Editor**', $result);

        // List items
        $this->assertStringContainsString('- **Markdown Editor**', $result);

        // Links
        $this->assertStringContainsString('[https://github.com/derekross/onyx](https://github.com/derekross/onyx)', $result);

        // Table pipes
        $this->assertStringContainsString('| Kind | Purpose | Encryption |', $result);

        // Table rows must be on consecutive lines (no blank lines between them)
        $this->assertStringContainsString(
            "| Kind | Purpose | Encryption |\n|------|---------|------------|\n| 30800 | File content | NIP-44 (self) |",
            $result,
        );

        // No <br> tags left
        $this->assertStringNotContainsString('<br', $result);

        // No triple+ newlines
        $this->assertDoesNotMatchRegularExpression('/\n{3,}/', $result);
    }
}


