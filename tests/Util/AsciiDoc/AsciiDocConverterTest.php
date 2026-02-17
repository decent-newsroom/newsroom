<?php

namespace App\Tests\Util\AsciiDoc;

use App\Util\AsciiDoc\AsciiDocConverter;
use PHPUnit\Framework\TestCase;

class AsciiDocConverterTest extends TestCase
{
    private AsciiDocConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new AsciiDocConverter();
    }

    public function testTextCenterAttribute(): void
    {
        $asciidoc = <<<'ASCIIDOC'
[.text-center]
21 Lessons, by Gigi
ASCIIDOC;

        $expected = '<p class="text-center">21 Lessons, by Gigi</p>';
        $result = trim($this->converter->convert($asciidoc));

        $this->assertStringContainsString('class="text-center"', $result);
        $this->assertStringContainsString('21 Lessons, by Gigi', $result);
    }

    public function testMultipleCenteredLines(): void
    {
        $asciidoc = <<<'ASCIIDOC'
[.text-center]
21 Lessons, by Gigi

[.text-center]
What I've Learned from Falling Down the Bitcoin Rabbit Hole

[.text-center]
Independently published 18. Dezember 2019.
ASCIIDOC;

        $result = $this->converter->convert($asciidoc);

        $this->assertStringContainsString('class="text-center"', $result);
        $this->assertStringContainsString('21 Lessons, by Gigi', $result);
        // Apostrophe will be HTML encoded
        $this->assertStringContainsString('What I', $result);
        $this->assertStringContainsString('ve Learned from Falling Down the Bitcoin Rabbit Hole', $result);
        $this->assertStringContainsString('Independently published 18. Dezember 2019.', $result);
    }

    public function testQuoteBlock(): void
    {
        $asciidoc = <<<'ASCIIDOC'
[quote]
"Oh, you foolish Alice!" she said again, "how can you learn lessons in here? Why, there's hardly room for you, and no room at all for any lesson-books!"
ASCIIDOC;

        $result = $this->converter->convert($asciidoc);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('Oh, you foolish Alice!', $result);
    }

    public function testCompleteExample(): void
    {
        $asciidoc = <<<'ASCIIDOC'
[.text-center]
21 Lessons, by Gigi

[.text-center]
What I've Learned from Falling Down the Bitcoin Rabbit Hole

[.text-center]
Independently published 18. Dezember 2019.

[quote]
"Oh, you foolish Alice!" she said again, "how can you learn lessons in here? Why, there's hardly room for you, and no room at all for any lesson-books!"
ASCIIDOC;

        $result = $this->converter->convert($asciidoc);

        // Check for centered paragraphs
        $this->assertStringContainsString('class="text-center"', $result);

        // Check for blockquote
        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('</blockquote>', $result);

        // Check content is present
        $this->assertStringContainsString('21 Lessons, by Gigi', $result);
        $this->assertStringContainsString('Oh, you foolish Alice!', $result);
    }

    public function testHeadings(): void
    {
        $asciidoc = <<<'ASCIIDOC'
= Document Title

== Section 1

=== Subsection 1.1

==== Sub-subsection
ASCIIDOC;

        $result = $this->converter->convert($asciidoc);

        $this->assertStringContainsString('<h1>Document Title</h1>', $result);
        $this->assertStringContainsString('<h2>Section 1</h2>', $result);
        $this->assertStringContainsString('<h3>Subsection 1.1</h3>', $result);
        $this->assertStringContainsString('<h4>Sub-subsection</h4>', $result);
    }

    public function testInlineMarkup(): void
    {
        $asciidoc = 'This is *bold* and this is _italic_ and this is `code`.';

        $result = $this->converter->convert($asciidoc);

        $this->assertStringContainsString('<strong>bold</strong>', $result);
        $this->assertStringContainsString('<em>italic</em>', $result);
        $this->assertStringContainsString('<code>code</code>', $result);
    }

    public function testAdmonitionBlocks(): void
    {
        $asciidoc = <<<'ASCIIDOC'
[NOTE]
====
This is a note block
with multiple lines
====

[WARNING]
====
Be careful here!
====
ASCIIDOC;

        $result = $this->converter->convert($asciidoc);

        // Check for NOTE block
        $this->assertStringContainsString('class="admonition admonition-note"', $result);
        $this->assertStringContainsString('NOTE', $result);
        $this->assertStringContainsString('This is a note block', $result);

        // Check for WARNING block
        $this->assertStringContainsString('class="admonition admonition-warning"', $result);
        $this->assertStringContainsString('WARNING', $result);
        $this->assertStringContainsString('Be careful here!', $result);
    }

    public function testNoteBlockWithSignature(): void
    {
        $asciidoc = <<<'ASCIIDOC'
[NOTE]

====
bla bla
-- The editor

====
ASCIIDOC;

        $result = $this->converter->convert($asciidoc);

        $this->assertStringContainsString('class="admonition admonition-note"', $result);
        $this->assertStringContainsString('NOTE', $result);
        $this->assertStringContainsString('bla bla', $result);
        $this->assertStringContainsString('admonition-attribution', $result);
        $this->assertStringContainsString('The editor', $result);
    }

    public function testAdmonitionWithImage(): void
    {
        $asciidoc = <<<'ASCIIDOC'
[TIP]
====
Check out this diagram:

image::https://example.com/diagram.png[Helpful diagram]

This explains everything!
====
ASCIIDOC;

        $result = $this->converter->convert($asciidoc);

        $this->assertStringContainsString('class="admonition admonition-tip"', $result);
        $this->assertStringContainsString('TIP', $result);
        $this->assertStringContainsString('<img', $result);
        $this->assertStringContainsString('src="https://example.com/diagram.png"', $result);
        $this->assertStringContainsString('alt="Helpful diagram"', $result);
        $this->assertStringContainsString('This explains everything!', $result);
    }

    public function testImageWithWidthAndAlignment(): void
    {
        $asciidoc = <<<'ASCIIDOC'
image::https://example.com/photo.jpg[My photo, 300, align="center"]

image::https://example.com/logo.png[Logo, 150, align="right"]

Regular text here.
ASCIIDOC;

        $result = $this->converter->convert($asciidoc);

        // Check first image with center alignment
        $this->assertStringContainsString('src="https://example.com/photo.jpg"', $result);
        $this->assertStringContainsString('alt="My photo"', $result);
        $this->assertStringContainsString('max-width: 300px', $result);
        $this->assertStringContainsString('margin-left: auto', $result);
        $this->assertStringContainsString('margin-right: auto', $result);

        // Check second image with right alignment
        $this->assertStringContainsString('src="https://example.com/logo.png"', $result);
        $this->assertStringContainsString('alt="Logo"', $result);
        $this->assertStringContainsString('max-width: 150px', $result);
        $this->assertStringContainsString('float: right', $result);
        $this->assertStringContainsString('margin-left: 1rem', $result);
    }

    public function testImageWithOnlyWidth(): void
    {
        $asciidoc = 'image::https://example.com/banner.jpg[Banner, 600]';

        $result = $this->converter->convert($asciidoc);

        $this->assertStringContainsString('src="https://example.com/banner.jpg"', $result);
        $this->assertStringContainsString('alt="Banner"', $result);
        $this->assertStringContainsString('max-width: 600px', $result);
    }

    public function testDelimitedQuoteBlock(): void
    {
        $asciidoc = <<<'ASCIIDOC'
[quote]
____

Little Alice fell

d

o

w

n

the hOle,

bumped her head

and bruised her soul.
____
ASCIIDOC;

        $result = $this->converter->convert($asciidoc);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('Little Alice fell', $result);
        $this->assertStringContainsString('the hOle,', $result);
        $this->assertStringContainsString('bumped her head', $result);
        $this->assertStringContainsString('and bruised her soul.', $result);
        $this->assertStringContainsString('</blockquote>', $result);

        // Verify line breaks are preserved
        $this->assertStringContainsString('<br />', $result);

        // Verify the poem structure is maintained (not all collapsed into one line)
        $this->assertMatchesRegularExpression('/Little Alice fell.*?<br\s*\/>.*?d.*?<br\s*\/>.*?o.*?<br\s*\/>/s', $result);
    }

    public function testAudioBlock(): void
    {
        $asciidoc = 'audio::https://example.com/audio.m4a[Audio description]';

        $result = $this->converter->convert($asciidoc);

        $this->assertStringContainsString('<figure class="audio-block">', $result);
        $this->assertStringContainsString('<audio controls', $result);
        $this->assertStringContainsString('src="https://example.com/audio.m4a"', $result);
        $this->assertStringContainsString('<figcaption>Audio description</figcaption>', $result);
    }

    public function testAudioBlockWithDotPrefix(): void
    {
        $asciidoc = <<<'ASCIIDOC'
.Audio. Read by Guy Swan
audio::https://21lessons.com/assets/audio/21lessons/0-00.m4a[Audio. Read by Guy Swan]
ASCIIDOC;

        $result = $this->converter->convert($asciidoc);

        $this->assertStringContainsString('<audio controls', $result);
        $this->assertStringContainsString('src="https://21lessons.com/assets/audio/21lessons/0-00.m4a"', $result);
        $this->assertStringContainsString('Audio. Read by Guy Swan', $result);
    }

    public function testAttributedQuote(): void
    {
        $asciidoc = <<<'ASCIIDOC'
[quote, Morpheus, The Matrix]
____
What if I told you
the truth was right in front of you?
____
ASCIIDOC;

        $result = $this->converter->convert($asciidoc);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('What if I told you', $result);
        $this->assertStringContainsString('the truth was right in front of you?', $result);
        $this->assertStringContainsString('<footer class="blockquote-footer">', $result);
        $this->assertStringContainsString('Morpheus', $result);
        $this->assertStringContainsString('<cite>The Matrix</cite>', $result);
        $this->assertStringContainsString('</blockquote>', $result);
    }

    public function testAttributedQuoteWithAuthorOnly(): void
    {
        $asciidoc = <<<'ASCIIDOC'
[quote, Albert Einstein]
Everything should be made as simple as possible, but not simpler.
ASCIIDOC;

        $result = $this->converter->convert($asciidoc);

        $this->assertStringContainsString('<blockquote>', $result);
        $this->assertStringContainsString('Everything should be made as simple as possible', $result);
        $this->assertStringContainsString('<footer class="blockquote-footer">', $result);
        $this->assertStringContainsString('Albert Einstein', $result);
        $this->assertStringNotContainsString('<cite>', $result); // No source provided
    }
}


