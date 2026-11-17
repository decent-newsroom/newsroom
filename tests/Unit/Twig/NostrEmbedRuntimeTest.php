<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\NostrEmbedRuntime;
use App\Util\CommonMark\Converter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\UX\TwigComponent\ComponentRendererInterface;

class NostrEmbedRuntimeTest extends TestCase
{
    private ComponentRendererInterface $renderer;
    private LoggerInterface $logger;
    private Converter $converter;
    private Security $security;
    private NostrEmbedRuntime $runtime;

    protected function setUp(): void
    {
        $this->renderer = $this->createMock(ComponentRendererInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->converter = $this->createMock(Converter::class);
        $this->converter->method('withLocalRendering')->willReturnCallback(static fn (callable $render): mixed => $render());
        $this->security = $this->createMock(Security::class);
        $this->runtime = new NostrEmbedRuntime($this->renderer, $this->logger, $this->converter, $this->security);
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

    public function testGuestRebuildsLegacyCardsFromRawContentBeforeResolvingHighlights(): void
    {
        $raw = 'A cached nostr:nevent1reference';
        $cached = '<p>Before</p><div class="embedded-event-card">stale highlight</div>';
        $placeholder = '<div class="nostr-deferred-embed" data-nostr-bech="nevent1reference" data-nostr-type="highlight-nevent"></div>';
        $this->converter->expects(self::once())->method('convertToHTML')
            ->with($raw, null, 30023, [['d', 'story']], false)
            ->willReturn('<p>Before</p>' . $placeholder);
        $this->renderer->expects(self::once())->method('createAndRender')
            ->with('Molecules:NostrEmbed', [
                'bech' => 'nevent1reference',
                'type' => 'nevent',
                'renderHighlightCard' => true,
            ])->willReturn('<span hidden data-highlight-embed></span>');

        $result = $this->runtime->resolveEmbeds($cached, $raw, 30023, [['d', 'story']], false);

        self::assertStringNotContainsString('stale highlight', $result);
        self::assertStringContainsString('data-highlight-embed', $result);
        self::assertStringContainsString('<p>Before</p>', $result);
    }

    public function testSignedInReaderKeepsLegacyCachedHtml(): void
    {
        $this->security->method('getUser')->willReturn($this->createMock(UserInterface::class));
        $this->converter->expects(self::never())->method('convertToHTML');
        $html = '<div class="embedded-event-card">cached highlight</div>';
        self::assertSame($html, $this->runtime->resolveEmbeds($html, 'raw'));
    }

    public function testGuestWithNoRawContentDoesNotSeeAnUnverifiableLegacyCard(): void
    {
        $this->logger->expects(self::once())->method('warning')
            ->with('Cannot safely render cached Nostr cards without their source content.');
        self::assertSame('', $this->runtime->resolveEmbeds('<div class="embedded-event-card">cached highlight</div>'));
    }

    public function testLegacyRebuildFailureLogsAndEscapesTheRawContent(): void
    {
        $this->converter->method('convertToHTML')->willThrowException(new \RuntimeException('conversion failed'));
        $this->logger->expects(self::once())->method('error')
            ->with('Could not rebuild cached Nostr cards for a guest.', self::anything());
        self::assertSame(
            '<pre>&lt;raw&gt;</pre>',
            $this->runtime->resolveEmbeds('<div class="embedded-event-card">stale</div>', '<raw>'),
        );
    }

    public function testNestedConversionLeavesPlaceholdersUnresolvedForSharedCaches(): void
    {
        $this->converter->method('isConverting')->willReturn(true);
        $this->renderer->expects(self::never())->method('createAndRender');
        $this->converter->expects(self::never())->method('convertToHTML');
        $html = '<div class="nostr-deferred-embed" data-nostr-bech="nevent1a" data-nostr-type="highlight-nevent"></div>';
        self::assertSame($html, $this->runtime->resolveEmbeds($html, 'raw'));
    }

    public function testHighlightOnlyResolutionLeavesOtherDeferredEmbedsUntouched(): void
    {
        $other = '<div class="nostr-deferred-embed" data-nostr-bech="note1a" data-nostr-type="note"></div>';
        $highlight = '<div class="nostr-deferred-embed" data-nostr-bech="naddr1b" data-nostr-type="highlight-naddr"></div>';
        $this->renderer->expects(self::once())->method('createAndRender')
            ->with('Molecules:NostrEmbed', [
                'bech' => 'naddr1b',
                'type' => 'naddr',
                'renderHighlightCard' => true,
            ])->willReturn('<div>Highlight</div>');

        self::assertSame(
            $other . '<div>Highlight</div>',
            $this->runtime->resolveEmbeds($other . $highlight, checkLegacyCards: false, highlightOnly: true),
        );
    }

    public function testRecursiveHighlightEmbedIsOmittedWithoutBlockingSiblingOccurrences(): void
    {
        $placeholder = '<div class="nostr-deferred-embed" data-nostr-bech="nevent1a" data-nostr-type="highlight-nevent"></div>';
        $this->renderer->expects(self::exactly(2))->method('createAndRender')
            ->willReturnCallback(fn (): string => '<article>' . $this->runtime->resolveEmbeds($placeholder) . '</article>');
        $this->logger->expects(self::exactly(2))->method('warning');

        self::assertSame('<article></article><article></article>', $this->runtime->resolveEmbeds($placeholder . $placeholder));
    }
}
