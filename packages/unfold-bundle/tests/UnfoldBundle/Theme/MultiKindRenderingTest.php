<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Theme;

use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Content\ContentKindPolicy;
use DecentNewsroom\UnfoldBundle\Content\PostData;
use DecentNewsroom\UnfoldBundle\Contract\CommentProviderInterface;
use DecentNewsroom\UnfoldBundle\Contract\Comment;
use DecentNewsroom\UnfoldBundle\Contract\MarkdownConverterInterface;
use DecentNewsroom\UnfoldBundle\Contract\ProfileMetadata;
use DecentNewsroom\UnfoldBundle\Contract\ProfileMetadataProviderInterface;
use DecentNewsroom\UnfoldBundle\Contract\ReaderBootstrapInterface;
use DecentNewsroom\UnfoldBundle\Http\PublicationUrlGenerator;
use DecentNewsroom\UnfoldBundle\Theme\ContextBuilder;
use DecentNewsroom\UnfoldBundle\Theme\HandlebarsRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MultiKindRenderingTest extends TestCase
{
    public function testEveryKindPassesExactFormatKindAndTagsAndSharesListDetailUrls(): void
    {
        $converter = $this->createMock(MarkdownConverterInterface::class);
        $converter->expects(self::exactly(4))->method('convertToHTML')
            ->willReturnCallback(static function (string $source, ?string $format, ?int $kind, ?array $tags): string {
                self::assertSame(ContentKindPolicy::format($kind), $format);
                self::assertSame([['k', '7']], $tags);

                return '<p>' . htmlspecialchars($source, ENT_QUOTES) . '</p>';
            });
        $builder = $this->builder($converter);
        foreach (ContentKindPolicy::KINDS as $kind) {
            // Misleading-looking source must not choose the parser.
            $post = $this->post($kind, 'same', [['k', '7']], $kind === 30023 ? '= AsciiDoc-looking' : '# Markdown-looking');
            $context = $builder->buildPostContext($this->site(), [], $post);
            $list = $builder->buildHomeContext($this->site(), [], [$post]);
            self::assertSame(PublicationUrlGenerator::postPath($post), $context['post']['url']);
            self::assertSame($context['post']['url'], $list['posts'][0]['url']);
            self::assertSame($kind, $context['post']['kind']);
            self::assertSame(['7'], $context['post']['referenced_kinds']);
            // Second rendering is cached with full kind/tags/event identity.
            self::assertSame($context['post']['html'], $builder->buildPostContext($this->site(), [], $post)['post']['html']);
        }
    }

    public function testTagsAndEventRevisionInvalidateHtmlWithoutChangingCoordinate(): void
    {
        $converter = $this->createMock(MarkdownConverterInterface::class);
        $converter->expects(self::exactly(3))->method('convertToHTML')->willReturn('<p>Rendered</p>');
        $builder = $this->builder($converter);
        $first = $this->post(30041, 'chapter', [['x', 'first']]);
        $second = $this->post(30041, 'chapter', [['x', 'second']]);
        $third = new PostData('chapter', 'Title', '', 'Body', null, 1, $first->pubkey, $first->coordinate, kind: 30041, tags: $second->tags, eventId: 'new-event');
        foreach ([$first, $second, $third] as $post) {
            self::assertSame('<p>Rendered</p>', $builder->buildPostContext($this->site(), [], $post)['post']['html']);
        }
    }

    public function testWikiLinksResolveOnlyUniqueLocalOrExplicitAuthorsAndEscapeLabels(): void
    {
        $target = $this->post(30818, '日本語-topic');
        $ambiguousA = $this->post(30818, 'collision');
        $ambiguousB = $this->post(30818, 'collision', [], 'Body', str_repeat('b', 64));
        $source = $this->post(30818, 'source', [], '[[日本語 Topic|<unsafe>]] [[collision]] [[' . $ambiguousB->coordinate . '|Exact writer]] [[global subject]]');
        $converter = $this->createMock(MarkdownConverterInterface::class);
        $converter->method('convertToHTML')->willReturnCallback(static fn(string $content): string => '<p>' . htmlspecialchars($content, ENT_QUOTES) . '</p>');
        $builder = $this->builder($converter);
        $context = $builder->buildPostContext($this->site(), [], $source, null, [$target, $ambiguousA, $ambiguousB]);
        $html = $context['post']['html'];
        self::assertStringContainsString('href="' . PublicationUrlGenerator::postPath($target) . '"', $html);
        self::assertStringContainsString('&lt;unsafe&gt;', $html);
        self::assertStringContainsString('href="' . PublicationUrlGenerator::postPath($ambiguousB) . '">Exact writer</a>', $html);
        self::assertStringContainsString('https://platform.example/search?q=collision', html_entity_decode($html));
        self::assertStringContainsString('https://platform.example/search?q=global-subject', html_entity_decode($html));
        self::assertStringNotContainsString('href="' . PublicationUrlGenerator::postPath($ambiguousA) . '"', $html);
        // Change membership: the formerly unique subject now needs search.
        $duplicate = $this->post(30818, '日本語-topic', [], 'Body', str_repeat('b', 64));
        $changed = $builder->buildPostContext($this->site(), [], $source, null, [$target, $duplicate]);
        self::assertStringNotContainsString('href="' . PublicationUrlGenerator::postPath($target) . '"', $changed['post']['html']);
    }

    public function testWikiTokensDoNotBecomeLinksInsideCodeOrAttributes(): void
    {
        $post = $this->post(30818, 'wiki', [], '[[target]]');
        $converter = $this->createMock(MarkdownConverterInterface::class);
        $converter->method('convertToHTML')->willReturnCallback(static fn(string $content): string => '<code>' . $content . '</code><span title="' . $content . '">Text</span>');
        $html = $this->builder($converter)->buildPostContext($this->site(), [], $post)['post']['html'];
        self::assertStringContainsString('<code>[[target]]</code>', $html);
        self::assertStringContainsString('title="[[target]]"', $html);
        self::assertStringNotContainsString('<a ', $html);
    }

    public function testBothThemesDisplayCommunityAttributionWithEscapedMetadata(): void
    {
        $converter = $this->createMock(MarkdownConverterInterface::class);
        $converter->method('convertToHTML')->willReturn('<p>Specification body</p>');
        $bootstrap = $this->createMock(ReaderBootstrapInterface::class);
        $bootstrap->method('render')->willReturn('<div class="unfold-reader-signer" data-controller="utility--signer-modal"></div>');
        $builder = $this->builder($converter, $bootstrap);
        $context = $builder->buildPostContext($this->site(), [], $this->post(30817, 'custom-nip'));
        self::assertTrue($context['post']['is_community_specification']);
        $renderer = new HandlebarsRenderer(
            new NullLogger(),
            dirname(__DIR__, 3) . '/Resources/themes',
            dirname(__DIR__, 5) . '/var/cache/unfold-public-content-tests',
        );
        foreach (['default', 'docs'] as $theme) {
            $renderer->setTheme($theme);
            $html = $renderer->render('post', $context);
            self::assertStringContainsString('unfold_public.community_specification', $html);
            self::assertStringContainsString('unfold_public.authored_by', $html);
            self::assertStringContainsString(str_repeat('a', 64), $html);
            self::assertStringContainsString('&lt;Writer&gt;', $html);
            self::assertStringContainsString('<p>Specification body</p>', $html);
            self::assertStringContainsString('class="unfold-interactions"', $html);
            self::assertStringContainsString('data-unfold-interactions', $html);
            self::assertStringContainsString('/unfold-themes/default/interactions.css', $html);
            if ($theme === 'docs') {
                self::assertStringContainsString('/unfold-themes/docs/interactions.css', $html);
            }
            self::assertStringContainsString('class="unfold-reader-signer"', $html);
            self::assertStringContainsString('data-controller="utility--signer-modal"', $html);
            self::assertStringNotContainsString('<Writer>', $html);
        }
    }

    public function testScopedContentNeverUsesConverterOrHtmlCache(): void
    {
        $converter = $this->createMock(MarkdownConverterInterface::class);
        $converter->expects(self::never())->method('convertToHTML');
        $post = $this->post(30817, 'secret', [['s', 'members']]);
        $this->expectException(NotFoundHttpException::class);
        $this->builder($converter)->buildPostContext($this->site(), [], $post);
    }

    public function testBothThemesUseTranslatedReplyLabelsAndMainDomainProfileLinksWithoutZapReplies(): void
    {
        $converter = $this->createMock(MarkdownConverterInterface::class);
        $converter->method('convertToHTML')->willReturn('<p>Body</p>');
        $pubkey = str_repeat('c', 64);
        $context = $this->builder($converter, comments: [
            new Comment(str_repeat('b', 64), 1111, $pubkey, 'Comment', 100),
            new Comment(str_repeat('d', 64), 9735, $pubkey, '', 200),
        ])->buildPostContext($this->site(), [], $this->post(30023, 'story'));
        $renderer = new HandlebarsRenderer(new NullLogger(), dirname(__DIR__, 3) . '/Resources/themes',
            dirname(__DIR__, 5) . '/var/cache/unfold-public-content-tests');
        foreach (['default', 'docs'] as $theme) {
            $renderer->setTheme($theme);
            $document = new \DOMDocument();
            $document->loadHTML($renderer->render('post', $context), LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
            $xpath = new \DOMXPath($document);
            self::assertSame(1, $xpath->query('//button[@data-unfold-reply-target]')->length);
            self::assertSame('unfold_interactions.reply', trim($xpath->evaluate('string(//button[@data-unfold-reply-target])')));
            self::assertSame(str_repeat('b', 64), $xpath->evaluate('string(//button/@data-unfold-reply-target)'));
            self::assertSame(2, $xpath->query('//a[@href="https://platform.example/p/' . \nostriphant\NIP19\Bech32::npub($pubkey) . '"]')->length);
        }
    }

    public function testRawConverterHtmlIsSanitizedBeforeThemeAndCache(): void
    {
        $converter = $this->createMock(MarkdownConverterInterface::class);
        $converter->expects(self::exactly(4))->method('convertToHTML')->willReturn(
            '<script>alert(1)</script><img src="https://images.example/safe.png" onerror="alert(2)">'
            . '<a href="javascript:alert(3)" onclick="alert(4)">Unsafe</a>'
            . '<p style="position:fixed">Safe <strong>text</strong></p>',
        );
        $builder = $this->builder($converter);
        foreach (ContentKindPolicy::KINDS as $kind) {
            $html = $builder->buildPostContext($this->site(), [], $this->post($kind, 'sanitize'))['post']['html'];
            self::assertStringNotContainsString('<script', $html);
            self::assertStringNotContainsString('onerror', $html);
            self::assertStringNotContainsString('javascript:', $html);
            self::assertStringNotContainsString('onclick', $html);
            self::assertStringNotContainsString('style=', $html);
            self::assertStringContainsString('<strong>text</strong>', $html);
            self::assertStringContainsString('https://images.example/safe.png', $html);
        }
    }

    private function post(int $kind, string $slug, array $tags = [], string $content = 'Body', ?string $author = null): PostData
    {
        $author ??= str_repeat('a', 64);

        return new PostData($slug, '<Title>', '', $content, null, 1, $author, $kind . ':' . $author . ':' . $slug, kind: $kind, tags: $tags);
    }

    private function site(): SiteConfig
    {
        $author = str_repeat('a', 64);

        return new SiteConfig('30040:' . $author . ':root', 'Publication', '', null, [], $author);
    }

    private function builder(MarkdownConverterInterface $converter, ?ReaderBootstrapInterface $bootstrap = null, array $comments = []): ContextBuilder
    {
        $profiles = $this->createMock(ProfileMetadataProviderInterface::class);
        $profiles->method('getMetadata')->willReturn(new ProfileMetadata(displayName: '<Writer>'));

        $provider = $this->createMock(CommentProviderInterface::class);
        $provider->method('findByCoordinate')->willReturn($comments);
        return new ContextBuilder($converter, new ArrayAdapter(), $profiles, $provider, platformBaseUrl: 'https://platform.example', readerBootstrap: $bootstrap);
    }
}
