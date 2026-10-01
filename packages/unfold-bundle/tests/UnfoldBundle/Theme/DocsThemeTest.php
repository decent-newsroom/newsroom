<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Theme;

use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettingsManager;
use DecentNewsroom\UnfoldBundle\Config\SiteConfigLoader;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSettingsStoreInterface;
use DecentNewsroom\UnfoldBundle\Theme\HandlebarsRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DocsThemeTest extends TestCase
{
    public function testDocsThemeIsDiscoveredAndRendersDocumentationArticleLayout(): void
    {
        $renderer = new HandlebarsRenderer(
            new NullLogger(),
            dirname(__DIR__, 3) . '/Resources/themes',
            sys_get_temp_dir() . '/unfold-docs-theme-' . uniqid('', true),
        );

        self::assertContains('docs', $renderer->getAvailableThemes());

        $renderer->setTheme('docs');
        $html = $renderer->render('post', [
            'site' => [
                'title' => 'Developer Handbook',
                'locale' => 'en',
                'navigation' => [['label' => 'Guides', 'url' => '/guides', 'current' => true]],
                'home_current' => false,
                'about_current' => false,
                'about_label' => 'About',
            ],
            'post' => [
                'title' => 'Install the client',
                'excerpt' => 'Set up a local client.',
                'published_at_formatted' => 'January 1, 2026',
                'reading_time' => 2,
                'html' => '<h2>Requirements</h2><p>PHP 8.3</p><h3>Optional tools</h3>',
                'feature_image' => null,
                'primary_author' => ['name' => 'Writer'],
                'primary_tag' => ['name' => 'Guides', 'url' => '/guides'],
                'zap' => ['lud16' => null],
                'has_thread_activity' => false,
            ],
            'publication_footer' => [
                'title' => 'Developer Handbook',
                'label' => 'Publication',
                'navigation' => [['label' => 'Home', 'url' => '/']],
                'owner_links' => [],
            ],
            'dn_footer' => [
                'brand_url' => 'https://example.test/unfold',
                'powered_by' => 'Powered by Decent Newsroom',
            ],
        ]);

        self::assertStringContainsString('class="docs-sidebar"', $html);
        self::assertStringContainsString('class="docs-content" data-docs-content', $html);
        self::assertStringContainsString('data-docs-toc', $html);
        self::assertStringContainsString('/unfold-themes/docs/docs.css', $html);
        self::assertStringContainsString('/unfold-themes/docs/docs.js', $html);
        self::assertStringContainsString('<h2>Requirements</h2>', $html);
    }

    public function testDocsThemeRendersHomeCategoryAndAboutPages(): void
    {
        $renderer = new HandlebarsRenderer(
            new NullLogger(),
            dirname(__DIR__, 3) . '/Resources/themes',
            sys_get_temp_dir() . '/unfold-docs-theme-pages-' . uniqid('', true),
        );
        $renderer->setTheme('docs');

        $site = [
            'title' => 'Developer Handbook',
            'description' => 'Documentation for the platform.',
            'locale' => 'en',
            'navigation' => [['label' => 'Guides', 'url' => '/guides', 'current' => false]],
            'home_current' => true,
            'about_current' => false,
            'about_label' => 'About',
        ];
        $footer = [
            'publication_footer' => [
                'title' => 'Developer Handbook',
                'label' => 'Publication',
                'navigation' => [['label' => 'Home', 'url' => '/']],
                'owner_links' => [],
            ],
            'dn_footer' => [
                'brand_url' => 'https://example.test/unfold',
                'powered_by' => 'Powered by Decent Newsroom',
            ],
        ];
        $post = [
            'title' => 'Getting started',
            'url' => '/a/getting-started',
            'excerpt' => 'First steps.',
            'published_at_formatted' => 'January 1, 2026',
            'primary_author' => ['name' => 'Writer'],
        ];

        $home = $renderer->render('index', ['site' => $site, 'posts' => [$post], ...$footer]);
        $category = $renderer->render('category', [
            'site' => $site,
            'category' => ['title' => 'Guides', 'summary' => 'Practical guides.'],
            'posts' => [$post],
            ...$footer,
        ]);
        $about = $renderer->render('about', [
            'site' => $site,
            'about' => [
                'title' => 'About',
                'has_article' => false,
                'intro_text' => 'Platform documentation.',
                'magazine_people_label' => 'Maintainers',
                'magazine_people' => [['name' => 'Alice', 'url' => 'https://example.test/p/alice']],
                'featured_writers_label' => 'Contributors',
                'featured_writers' => [['name' => 'Bob', 'url' => 'https://example.test/p/bob']],
            ],
            ...$footer,
        ]);

        self::assertStringContainsString('href="/a/getting-started"', $home);
        self::assertStringContainsString('<h1>Guides</h1>', $category);
        self::assertStringContainsString('Platform documentation.', $about);
    }

    public function testDiscoveredDocsThemeCanBeSavedForAPublication(): void
    {
        $renderer = new HandlebarsRenderer(
            new NullLogger(),
            dirname(__DIR__, 3) . '/Resources/themes',
            sys_get_temp_dir() . '/unfold-docs-theme-settings-' . uniqid('', true),
        );
        $coordinate = '30040:' . str_repeat('a', 64) . ':docs';
        $store = $this->createMock(PublicationSettingsStoreInterface::class);
        $store->expects(self::once())
            ->method('save')
            ->with(self::callback(
                static fn (PublicationSettings $settings): bool => $settings->coordinate === $coordinate
                    && $settings->theme === 'docs',
            ));
        $loader = $this->createMock(SiteConfigLoader::class);
        $loader->expects(self::once())->method('invalidateFromCoordinate')->with($coordinate);

        (new PublicationSettingsManager($store, $renderer, $loader))->saveTheme($coordinate, 'docs');
    }
}
