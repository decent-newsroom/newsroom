<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Theme;

use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Content\CategoryData;
use DecentNewsroom\UnfoldBundle\Content\PostData;
use DecentNewsroom\UnfoldBundle\Contract\CommentProviderInterface;
use DecentNewsroom\UnfoldBundle\Contract\MarkdownConverterInterface;
use DecentNewsroom\UnfoldBundle\Contract\ProfileMetadata;
use DecentNewsroom\UnfoldBundle\Contract\ProfileMetadataProviderInterface;
use DecentNewsroom\UnfoldBundle\Theme\ContextBuilder;
use DecentNewsroom\UnfoldBundle\Theme\HandlebarsRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Translation\TranslatorInterface;

final class FooterContextTest extends TestCase
{
    public function testPublicationLinksRemainSeparateAndRenderEscapedOnEveryPage(): void
    {
        $converter = $this->createMock(MarkdownConverterInterface::class);
        $converter->method('convertToHTML')->willReturn('<p>Body</p>');
        $profiles = $this->createMock(ProfileMetadataProviderInterface::class);
        $profiles->method('getMetadata')->willReturn(new ProfileMetadata());
        $comments = $this->createMock(CommentProviderInterface::class);
        $comments->method('findByCoordinate')->willReturn([]);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $key): string => $key,
        );

        $builder = new ContextBuilder(
            $converter,
            new ArrayAdapter(),
            $profiles,
            $comments,
            $translator,
            'https://dn.example',
        );
        $site = new SiteConfig(
            naddr: '30040:' . str_repeat('a', 64) . ':mag',
            title: 'Publication',
            description: '',
            logo: null,
            categories: [],
            pubkey: str_repeat('a', 64),
            footerLinks: [
                ['label' => 'Owner & Team', 'url' => 'https://owner.example/?a=1&b=2'],
            ],
        );
        $category = new CategoryData('culture', 'Culture', '30040:' . str_repeat('a', 64) . ':culture');
        $post = new PostData('story', 'Story', 'Summary', 'Body', null, 100, str_repeat('a', 64), '30023:' . str_repeat('a', 64) . ':story');

        $contexts = [
            'index' => $builder->buildHomeContext($site, [$category], []),
            'category' => $builder->buildCategoryContext($site, [$category], $category, []),
            'post' => $builder->buildPostContext($site, [$category], $post),
        ];
        $renderer = new HandlebarsRenderer(
            new NullLogger(),
            dirname(__DIR__, 3) . '/Resources/themes',
            sys_get_temp_dir() . '/unfold-footer-test-' . uniqid('', true),
        );

        foreach ($contexts as $template => $context) {
            self::assertSame($site->footerLinks, $context['publication_footer']['owner_links']);
            self::assertSame('https://dn.example/unfold', $context['dn_footer']['brand_url']);
            self::assertSame(['/', '/rss.xml', '/sitemap.xml'], array_column($context['publication_footer']['navigation'], 'url'));
            self::assertSame(['https://dn.example/about', 'https://dn.example/tos'], array_column($context['dn_footer']['links'], 'url'));
            self::assertNotContains($site->footerLinks[0], $context['dn_footer']['links']);

            $html = $renderer->render($template, $context);
            self::assertStringContainsString('class="unfold-footer__publication"', $html);
            self::assertStringContainsString('class="unfold-footer__platform"', $html);
            self::assertStringContainsString(
                'href="https://owner.example/?a&#x3D;1&amp;b&#x3D;2" rel="noopener noreferrer">Owner &amp; Team</a>',
                $html,
            );
            self::assertStringContainsString('href="https://dn.example/tos"', $html);
            self::assertStringContainsString('href="/sitemap.xml"', $html);
            self::assertStringNotContainsString('href="https://dn.example/sitemap.xml"', $html);
        }
    }
}
