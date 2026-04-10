<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\NostrEmbedRuntime;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\UX\TwigComponent\ComponentRendererInterface;

class NostrEmbedRuntimeTest extends TestCase
{
    private ComponentRendererInterface $renderer;
    private LoggerInterface $logger;
    private NostrEmbedRuntime $runtime;

    protected function setUp(): void
    {
        $this->renderer = $this->createMock(ComponentRendererInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->runtime = new NostrEmbedRuntime($this->renderer, $this->logger);
    }

    // ── Null / empty input ───────────────────────────────────────

    public function testNullInputReturnsEmptyString(): void
    {
        $this->assertSame('', $this->runtime->resolveEmbeds(null));
    }

    public function testEmptyStringReturnsEmptyString(): void
    {
        $this->assertSame('', $this->runtime->resolveEmbeds(''));
    }

    // ── No placeholders → short-circuit ──────────────────────────

    public function testHtmlWithoutPlaceholdersIsReturnedUnchanged(): void
    {
        $html = '<p>Hello world</p><div class="something">Test</div>';

        $this->renderer->expects($this->never())->method('createAndRender');

        $this->assertSame($html, $this->runtime->resolveEmbeds($html));
    }

    // ── Single placeholder resolution ────────────────────────────

    public function testSingleNotePlaceholderIsReplaced(): void
    {
        $bech = 'note1abc123def456xyz';
        $html = '<p>Before</p><div class="nostr-deferred-embed" data-nostr-bech="' . $bech . '" data-nostr-type="note"></div><p>After</p>';

        $this->renderer
            ->expects($this->once())
            ->method('createAndRender')
            ->with('Molecules:NostrEmbed', ['bech' => $bech, 'type' => 'note'])
            ->willReturn('<div class="nostr-embed-card">Rendered note</div>');

        $result = $this->runtime->resolveEmbeds($html);

        $this->assertStringContainsString('Rendered note', $result);
        $this->assertStringNotContainsString('nostr-deferred-embed', $result);
        $this->assertStringContainsString('<p>Before</p>', $result);
        $this->assertStringContainsString('<p>After</p>', $result);
    }

    public function testNeventPlaceholderIsReplaced(): void
    {
        $bech = 'nevent1qqsduunanrnj9laeddyeaahmlgxa9xfrc9neyslmsy5kavfw08dcjpq';
        $html = '<div class="nostr-deferred-embed" data-nostr-bech="' . $bech . '" data-nostr-type="nevent"></div>';

        $this->renderer
            ->expects($this->once())
            ->method('createAndRender')
            ->with('Molecules:NostrEmbed', ['bech' => $bech, 'type' => 'nevent'])
            ->willReturn('<div>Rendered nevent</div>');

        $result = $this->runtime->resolveEmbeds($html);

        $this->assertSame('<div>Rendered nevent</div>', $result);
    }

    public function testNaddrPlaceholderIsReplaced(): void
    {
        $bech = 'naddr1qqs0example123';
        $html = '<div class="nostr-deferred-embed" data-nostr-bech="' . $bech . '" data-nostr-type="naddr"></div>';

        $this->renderer
            ->expects($this->once())
            ->method('createAndRender')
            ->with('Molecules:NostrEmbed', ['bech' => $bech, 'type' => 'naddr'])
            ->willReturn('<article>Rendered article embed</article>');

        $result = $this->runtime->resolveEmbeds($html);

        $this->assertSame('<article>Rendered article embed</article>', $result);
    }

    // ── Multiple placeholders ────────────────────────────────────

    public function testMultiplePlaceholdersAreAllReplaced(): void
    {
        $html = '<p>Intro</p>'
            . '<div class="nostr-deferred-embed" data-nostr-bech="note1aaa" data-nostr-type="note"></div>'
            . '<p>Middle</p>'
            . '<div class="nostr-deferred-embed" data-nostr-bech="nevent1bbb" data-nostr-type="nevent"></div>'
            . '<p>End</p>';

        $this->renderer
            ->expects($this->exactly(2))
            ->method('createAndRender')
            ->willReturnCallback(function (string $name, array $props): string {
                return match ($props['bech']) {
                    'note1aaa' => '<div>NOTE</div>',
                    'nevent1bbb' => '<div>NEVENT</div>',
                    default => '',
                };
            });

        $result = $this->runtime->resolveEmbeds($html);

        $this->assertStringContainsString('<div>NOTE</div>', $result);
        $this->assertStringContainsString('<div>NEVENT</div>', $result);
        $this->assertStringNotContainsString('nostr-deferred-embed', $result);
        $this->assertStringContainsString('<p>Intro</p>', $result);
        $this->assertStringContainsString('<p>Middle</p>', $result);
        $this->assertStringContainsString('<p>End</p>', $result);
    }

    // ── Error handling ───────────────────────────────────────────

    public function testRenderExceptionRetainsOriginalPlaceholder(): void
    {
        $bech = 'note1broken';
        $placeholder = '<div class="nostr-deferred-embed" data-nostr-bech="' . $bech . '" data-nostr-type="note"></div>';
        $html = '<p>Before</p>' . $placeholder . '<p>After</p>';

        $this->renderer
            ->expects($this->once())
            ->method('createAndRender')
            ->willThrowException(new \RuntimeException('Component render failed'));

        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                'NostrEmbedRuntime: component render failed',
                $this->callback(function (array $ctx) use ($bech): bool {
                    return $ctx['bech'] === $bech
                        && $ctx['type'] === 'note'
                        && str_contains($ctx['error'], 'Component render failed');
                })
            );

        $result = $this->runtime->resolveEmbeds($html);

        // Original placeholder is preserved
        $this->assertStringContainsString($placeholder, $result);
        $this->assertStringContainsString('<p>Before</p>', $result);
        $this->assertStringContainsString('<p>After</p>', $result);
    }

    public function testPartialFailureReplacesSuccessfulAndKeepsFailed(): void
    {
        $html = '<div class="nostr-deferred-embed" data-nostr-bech="note1good" data-nostr-type="note"></div>'
            . '<div class="nostr-deferred-embed" data-nostr-bech="note1bad" data-nostr-type="note"></div>';

        $this->renderer
            ->expects($this->exactly(2))
            ->method('createAndRender')
            ->willReturnCallback(function (string $name, array $props): string {
                if ($props['bech'] === 'note1good') {
                    return '<div>OK</div>';
                }
                throw new \RuntimeException('Nope');
            });

        $result = $this->runtime->resolveEmbeds($html);

        $this->assertStringContainsString('<div>OK</div>', $result);
        $this->assertStringContainsString('note1bad', $result);
        $this->assertStringContainsString('nostr-deferred-embed', $result);
    }

    // ── Extra data attributes don't break the regex ──────────────

    public function testPlaceholderWithExtraDataAttributesIsMatched(): void
    {
        $html = '<div class="nostr-deferred-embed" data-nostr-bech="note1extra" data-nostr-type="note" data-extra="foo"></div>';

        $this->renderer
            ->expects($this->once())
            ->method('createAndRender')
            ->with('Molecules:NostrEmbed', ['bech' => 'note1extra', 'type' => 'note'])
            ->willReturn('<div>Extra</div>');

        $result = $this->runtime->resolveEmbeds($html);

        $this->assertSame('<div>Extra</div>', $result);
    }

    // ── Surrounding HTML is preserved ────────────────────────────

    public function testSurroundingHtmlEntitiesArePreserved(): void
    {
        $html = '<h1>Title &amp; More</h1>'
            . '<div class="nostr-deferred-embed" data-nostr-bech="note1test" data-nostr-type="note"></div>'
            . '<p>Footer &lt;3</p>';

        $this->renderer
            ->expects($this->once())
            ->method('createAndRender')
            ->willReturn('<span>Embed</span>');

        $result = $this->runtime->resolveEmbeds($html);

        $this->assertStringContainsString('Title &amp; More', $result);
        $this->assertStringContainsString('Footer &lt;3', $result);
        $this->assertStringContainsString('<span>Embed</span>', $result);
    }

    // ── Keyword present but no matching div ──────────────────────

    public function testKeywordPresentButNoMatchingDivReturnsUnchanged(): void
    {
        // The class name is present as text content, but not in a matching div structure
        $html = '<p>We use nostr-deferred-embed as a class name for embeds.</p>';

        $this->renderer->expects($this->never())->method('createAndRender');

        $result = $this->runtime->resolveEmbeds($html);

        $this->assertSame($html, $result);
    }
}

