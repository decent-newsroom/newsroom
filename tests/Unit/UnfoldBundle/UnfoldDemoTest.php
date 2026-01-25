<?php

namespace App\Tests\Unit\UnfoldBundle;

use App\UnfoldBundle\Config\SiteConfig;
use App\UnfoldBundle\Content\CategoryData;
use App\UnfoldBundle\Content\PostData;
use App\UnfoldBundle\Theme\ContextBuilder;
use App\UnfoldBundle\Theme\HandlebarsRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Demo test for Unfold theme rendering with default theme
 */
class UnfoldDemoTest extends TestCase
{
    private HandlebarsRenderer $renderer;
    private ContextBuilder $contextBuilder;

    protected function setUp(): void
    {
        $projectDir = dirname(__DIR__, 3);
        $this->renderer = new HandlebarsRenderer(new NullLogger(), $projectDir);
        $this->contextBuilder = new ContextBuilder();
    }

    public function testRenderHomePageWithDefaultTheme(): void
    {
        // Use default theme
        $this->renderer->setTheme('default');

        // Create mock site config
        $siteConfig = new SiteConfig(
            naddr: 'naddr1qqxnzd3cxqmrzv3exgmr2wfeqgsxu35yyt0mwjjh8pcz4zprhxegz69t4wr9t74vk6zne58wzh0waycrqsqqqa28pjfdhz',
            title: 'Demo Magazine',
            description: 'A demonstration magazine built with Unfold',
            logo: 'https://picsum.photos/200',
            categories: ['30040:abc123:tech', '30040:abc123:culture'],
            pubkey: 'abc123def456',
            theme: 'default',
        );

        // Create mock categories
        $categories = [
            new CategoryData(
                slug: 'tech',
                title: 'Technology',
                coordinate: '30040:abc123:tech',
                articleCoordinates: ['30023:abc123:hello-world', '30023:abc123:future-of-ai'],
            ),
            new CategoryData(
                slug: 'culture',
                title: 'Culture',
                coordinate: '30040:abc123:culture',
                articleCoordinates: ['30023:abc123:art-review'],
            ),
        ];

        // Create mock posts
        $posts = [
            new PostData(
                slug: 'hello-world',
                title: 'Hello World: Welcome to Our Magazine',
                summary: 'This is the first article in our brand new magazine. We explore the exciting world of decentralized publishing.',
                content: '# Hello World\n\nWelcome to our magazine! This is the beginning of something amazing.',
                image: 'https://picsum.photos/800/400',
                publishedAt: strtotime('2026-01-09'),
                pubkey: 'abc123def456',
                coordinate: '30023:abc123:hello-world',
            ),
            new PostData(
                slug: 'future-of-ai',
                title: 'The Future of AI in Publishing',
                summary: 'Artificial intelligence is reshaping how we create and consume content. Here is what you need to know.',
                content: '# The Future of AI\n\nAI is transforming the publishing industry in remarkable ways.',
                image: 'https://picsum.photos/800/401',
                publishedAt: strtotime('2026-01-08'),
                pubkey: 'abc123def456',
                coordinate: '30023:abc123:future-of-ai',
            ),
            new PostData(
                slug: 'art-review',
                title: 'Modern Art in the Digital Age',
                summary: 'A look at how digital technology is influencing contemporary art movements.',
                content: '# Modern Art\n\nThe intersection of art and technology creates new possibilities.',
                image: 'https://picsum.photos/800/402',
                publishedAt: strtotime('2026-01-07'),
                pubkey: 'abc123def456',
                coordinate: '30023:abc123:art-review',
            ),
        ];

        // Build context
        $context = $this->contextBuilder->buildHomeContext($siteConfig, $categories, $posts);

        // Render using index template
        $html = $this->renderer->render('index', $context);

        // Assertions
        $this->assertNotEmpty($html);
        $this->assertStringContainsString('Demo Magazine', $html);
        $this->assertStringContainsString('Hello World', $html);

        // Output for visual inspection
        echo "\n\n=== RENDERED HOME PAGE (first 2000 chars) ===\n";
        echo substr($html, 0, 2000);
        echo "\n...\n";
    }

    public function testRenderPostPageWithDefaultTheme(): void
    {
        $this->renderer->setTheme('default');

        $siteConfig = new SiteConfig(
            naddr: 'naddr1qqxnzd3cxqmrzv3exgmr2wfeqgsxu35yyt0mwjjh8pcz4zprhxegz69t4wr9t74vk6zne58wzh0waycrqsqqqa28pjfdhz',
            title: 'Demo Magazine',
            description: 'A demonstration magazine',
            logo: null,
            categories: ['30040:abc123:tech'],
            pubkey: 'abc123def456',
            theme: 'default',
        );

        $categories = [
            new CategoryData(
                slug: 'tech',
                title: 'Technology',
                coordinate: '30040:abc123:tech',
                articleCoordinates: ['30023:abc123:hello-world'],
            ),
        ];

        $post = new PostData(
            slug: 'hello-world',
            title: 'Hello World: Welcome to Our Magazine',
            summary: 'This is the first article in our brand new magazine.',
            content: "# Hello World\n\nWelcome to our magazine! This is the beginning of something amazing.\n\n## What We Do\n\nWe publish great content on Nostr.\n\n> \"The future is decentralized.\" - Someone wise\n\n### Features\n\n- Decentralized publishing\n- No censorship\n- Own your content",
            image: 'https://picsum.photos/1200/600',
            publishedAt: strtotime('2026-01-09 10:30:00'),
            pubkey: 'abc123def456',
            coordinate: '30023:abc123:hello-world',
        );

        $context = $this->contextBuilder->buildPostContext($siteConfig, $categories, $post);

        // Render using post template
        $html = $this->renderer->render('post', $context);

        $this->assertNotEmpty($html);
        $this->assertStringContainsString('Hello World', $html);

        echo "\n\n=== RENDERED POST PAGE (first 2000 chars) ===\n";
        echo substr($html, 0, 2000);
        echo "\n...\n";
    }

    public function testAssetPathsAreCorrect(): void
    {
        $this->renderer->setTheme('default');

        $siteConfig = new SiteConfig(
            naddr: 'naddr1test',
            title: 'Test Site',
            description: 'Test',
            logo: null,
            categories: [],
            pubkey: 'test',
            theme: 'default',
        );

        $context = $this->contextBuilder->buildHomeContext($siteConfig, [], []);
        $html = $this->renderer->render('index', $context);

        // Check that asset paths point to default theme
        $this->assertStringContainsString('/assets/themes/default', $html);

        echo "\n\n=== ASSET PATHS CHECK ===\n";
        echo "✓ Asset paths use default theme\n";
    }
}

