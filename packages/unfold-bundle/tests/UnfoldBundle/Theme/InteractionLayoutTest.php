<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Theme;

use DecentNewsroom\UnfoldBundle\Theme\HandlebarsRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class InteractionLayoutTest extends TestCase
{
    /**
     * @dataProvider articleZapProvider
     */
    public function testArticleZapSharesTheToolbarAndPreservesPaymentAttributes(string $theme, ?array $zap): void
    {
        $html = $this->render($theme, $zap);
        $xpath = $this->xpath($html);
        $toolbar = '//*[contains(concat(" ", normalize-space(@class), " "), " unfold-interactions__toolbar ")]';
        self::assertSame(1, $xpath->query($toolbar . '//button[@data-unfold-like-action]')->length);
        self::assertSame(1, $xpath->query($toolbar . '//button[@data-unfold-repost-action]')->length);
        $buttons = $xpath->query($toolbar . '//button[@data-zap-button]');
        $eligible = !empty($zap['lud16']);
        self::assertSame($eligible ? 1 : 0, $buttons->length);
        self::assertSame($eligible ? 1 : 0, $xpath->query('//main//button[@data-zap-button and not(ancestor::footer)]')->length);

        if ($eligible) {
            $button = $buttons->item(0);
            self::assertSame($zap['pubkey'], $button->getAttribute('data-zap-pubkey'));
            self::assertSame($zap['lud16'], $button->getAttribute('data-zap-lud16'));
            self::assertSame($zap['lud06'] ?? '', $button->getAttribute('data-zap-lud06'));
            self::assertSame('Article writer', $button->getAttribute('data-zap-recipient'));
            self::assertSame(isset($zap['splits']), $button->hasAttribute('data-zap-splits'));
            if (isset($zap['splits'])) {
                self::assertSame($zap['splits'], json_decode($button->getAttribute('data-zap-splits'), true, flags: JSON_THROW_ON_ERROR));
            }
            self::assertStringContainsString('Zap', $button->textContent);
        }

        if ($theme === 'default') {
            $donation = $xpath->query('//footer//button[@data-zap-button]');
            self::assertSame(1, $donation->length);
            self::assertSame('publication@example.test', $donation->item(0)->getAttribute('data-zap-lud16'));
        }
    }

    public static function articleZapProvider(): iterable
    {
        foreach (['default', 'docs'] as $theme) {
            yield $theme . ' missing zap' => [$theme, null];
            yield $theme . ' lud06 only' => [$theme, ['pubkey' => str_repeat('a', 64), 'lud06' => 'lnurl1example']];
            yield $theme . ' lud16' => [$theme, ['pubkey' => str_repeat('a', 64), 'lud16' => 'writer@example.test']];
            yield $theme . ' splits' => [$theme, [
                'pubkey' => str_repeat('a', 64),
                'lud16' => 'writer@example.test',
                'lud06' => 'lnurl1example',
                'splits' => [['pubkey' => str_repeat('b', 64), 'weight' => 1]],
            ]];
        }
    }

    /**
     * @dataProvider themeProvider
     */
    public function testComposerHomeReplySlotAndSingleLoadMoreAreStable(string $theme): void
    {
        $xpath = $this->xpath($this->render($theme, null));
        self::assertSame(1, $xpath->query('//div[@data-unfold-composer-home]/form[@data-unfold-composer-form]')->length);
        self::assertSame(1, $xpath->query('//form[@data-unfold-composer-form]')->length);
        self::assertSame(1, $xpath->query('//article[@data-comment-id="comment-1"]/div[@data-unfold-reply-slot="comment-1"]')->length);
        self::assertSame(1, $xpath->query('//div[@data-unfold-reply-slot="comment-1"]/preceding-sibling::div[contains(@class, "unfold-interactions__comment-actions")]')->length);
        self::assertSame(1, $xpath->query('//button[@data-unfold-load-more]')->length);
    }

    public static function themeProvider(): iterable
    {
        yield 'default' => ['default'];
        yield 'docs' => ['docs'];
    }

    /**
     * @dataProvider themeProvider
     */
    public function testCommentAndZapCardsUseBorderlessSurfacesAndRoundAvatars(string $theme): void
    {
        $xpath = $this->xpath($this->render($theme, null));
        foreach (['comment-1', 'zap-1'] as $id) {
            $card = '//article[@data-comment-id="' . $id . '"]';
            self::assertSame(1, $xpath->query($card . '[contains(@class, "unfold-interactions__comment")]')->length);
            self::assertSame(1, $xpath->query($card . '//img[contains(@class, "unfold-interactions__avatar")]')->length);
        }

        $themes = dirname(__DIR__, 3) . '/Resources/themes';
        $css = file_get_contents($themes . '/default/assets/interactions.css');
        self::assertMatchesRegularExpression('/\.unfold-interactions__comment\s*\{[^}]*padding:\s*1rem;[^}]*border:\s*0;[^}]*background:\s*var\(--unfold-comment-background\);/s', $css);
        self::assertMatchesRegularExpression('/\.unfold-interactions__avatar\s*\{[^}]*border-radius:\s*50%;/s', $css);
        self::assertStringNotContainsString('border-left:', $css);
        self::assertStringNotContainsString('border-style: dashed', $css);
        self::assertStringContainsString('--unfold-comment-background:', file_get_contents($themes . '/' . $theme . '/assets/interactions.css'));
    }

    private function render(string $theme, ?array $zap): string
    {
        $renderer = new HandlebarsRenderer(
            new NullLogger(),
            dirname(__DIR__, 3) . '/Resources/themes',
            dirname(__DIR__, 5) . '/var/cache/unfold-interaction-layout-' . uniqid('', true),
        );
        $post = [
            'title' => 'Article',
            'html' => '<p>Article body</p>',
            'primary_author' => ['name' => 'Article writer'],
            'comments' => [[
                'id' => 'comment-1',
                'author' => ['name' => 'Reader', 'pic' => 'https://example.test/reader.png'],
                'content_html' => '<p>A comment</p>',
            ], [
                'id' => 'zap-1',
                'is_zap' => true,
                'zap_amount' => 21,
                'author' => ['name' => 'Zapper', 'pic' => 'https://example.test/zapper.png'],
                'content_html' => '<p>Thanks!</p>',
            ]],
            'interactions' => ['labels' => [
                'comments' => 'Comments',
                'like' => 'Like',
                'repost' => 'Repost',
                'zap' => 'Zap',
            ]],
        ];
        if ($zap !== null) {
            $post['zap'] = $zap;
        }

        return $renderer->render('post', [
            'site' => [
                'title' => 'Publication',
                'locale' => 'en',
                'creator_pubkey' => str_repeat('c', 64),
                'creator_lud16' => 'publication@example.test',
            ],
            'post' => $post,
        ], $theme);
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new \DOMXPath($document);
    }
}
