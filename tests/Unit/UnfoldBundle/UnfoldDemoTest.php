<?php

namespace App\Tests\Unit\UnfoldBundle;

use App\Entity\Event;
use App\Repository\EventRepository;
use App\UnfoldBundle\Config\SiteConfig;
use App\UnfoldBundle\Content\CategoryData;
use App\UnfoldBundle\Content\PostData;
use App\UnfoldBundle\Theme\ContextBuilder;
use App\UnfoldBundle\Theme\HandlebarsRenderer;
use App\Util\CommonMark\MarkdownConverterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use App\Service\Cache\RedisCacheService;
use App\Dto\UserMetadata;

/**
 * Demo test for Unfold theme rendering with default theme
 */
class UnfoldDemoTest extends TestCase
{
    private HandlebarsRenderer $renderer;
    private ContextBuilder $contextBuilder;
    private EventRepository $eventRepository;

    protected function setUp(): void
    {
        $projectDir = dirname(__DIR__, 3);
        $kernelCacheDir = sys_get_temp_dir() . '/unfold-test-cache-' . uniqid('', true);
        if (!is_dir($kernelCacheDir)) {
            mkdir($kernelCacheDir, 0777, true);
        }

        $this->renderer = new HandlebarsRenderer(new NullLogger(), $projectDir, $kernelCacheDir);

        // Create a mock Converter that performs basic HTML escaping
        $converter = $this->createMock(MarkdownConverterInterface::class);
        $converter->method('convertToHTML')
            ->willReturnCallback(function (string $markdown): string {
                // Basic markdown-like conversion for test purposes
                $html = htmlspecialchars($markdown, ENT_QUOTES, 'UTF-8');
                $html = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $html);
                $html = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $html);
                $html = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $html);
                $html = nl2br($html);
                return $html;
            });

        // Create a mock cache that always misses (so converter is called)
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $cacheItem->method('set')->willReturnSelf();
        $cacheItem->method('expiresAfter')->willReturnSelf();

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($cacheItem);
        $cache->method('save')->willReturn(true);

        // Mock Redis cache service (used for author metadata + creator lightning address)
        $redisCacheService = $this->createMock(RedisCacheService::class);
        $redisCacheService->method('getMetadata')->willReturn(new UserMetadata());
        $redisCacheService->method('getMultipleMetadata')->willReturn([]);

        $this->eventRepository = $this->createMock(EventRepository::class);
        $this->eventRepository->method('findCommentsByCoordinate')->willReturn([]);

        $this->contextBuilder = new ContextBuilder($converter, $cache, $redisCacheService, $this->eventRepository);
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
                summary: 'All things tech',
                articleCoordinates: ['30023:abc123:hello-world', '30023:abc123:future-of-ai'],
            ),
            new CategoryData(
                slug: 'culture',
                title: 'Culture',
                coordinate: '30040:abc123:culture',
                summary: 'Arts and culture',
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
        $this->assertStringContainsString('max-width: 800px', $html);

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
                summary: 'All about tech',
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

    public function testRenderCategoryPageWithDefaultThemeUsesPostWidth(): void
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

        $category = new CategoryData(
            slug: 'tech',
            title: 'Technology',
            coordinate: '30040:abc123:tech',
            summary: 'All about tech',
            articleCoordinates: ['30023:abc123:hello-world'],
        );

        $post = new PostData(
            slug: 'hello-world',
            title: 'Hello World: Welcome to Our Magazine',
            summary: 'This is the first article in our brand new magazine.',
            content: 'Content',
            image: 'https://picsum.photos/800/400',
            publishedAt: strtotime('2026-01-09 10:30:00'),
            pubkey: 'abc123def456',
            coordinate: '30023:abc123:hello-world',
        );

        $context = $this->contextBuilder->buildCategoryContext($siteConfig, [$category], $category, [$post]);
        $html = $this->renderer->render('category', $context);

        $this->assertNotEmpty($html);
        $this->assertStringContainsString('Technology', $html);
        $this->assertStringContainsString('max-width: 800px', $html);
        $this->assertStringNotContainsString('max-width: 1200px', $html);
    }

    public function testRenderPostPageShowsZeroCommentCountWhenThreadContainsOnlyZaps(): void
    {
        $this->renderer->setTheme('default');

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $cacheItem->method('set')->willReturnSelf();
        $cacheItem->method('expiresAfter')->willReturnSelf();

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($cacheItem);
        $cache->method('save')->willReturn(true);

        $converter = $this->createMock(MarkdownConverterInterface::class);
        $converter->method('convertToHTML')->willReturn('Content');

        $siteConfig = new SiteConfig(
            naddr: 'naddr1qqxnzd3cxqmrzv3exgmr2wfeqgsxu35yyt0mwjjh8pcz4zprhxegz69t4wr9t74vk6zne58wzh0waycrqsqqqa28pjfdhz',
            title: 'Demo Magazine',
            description: 'A demonstration magazine',
            logo: null,
            categories: [],
            pubkey: 'abc123def456',
            theme: 'default',
        );

        $post = new PostData(
            slug: 'hello-world',
            title: 'Hello World: Welcome to Our Magazine',
            summary: 'This is the first article in our brand new magazine.',
            content: 'Content',
            image: null,
            publishedAt: strtotime('2026-01-09 10:30:00'),
            pubkey: 'abc123def456',
            coordinate: '30023:abc123:hello-world',
        );

        $zap = new Event();
        $zap->setId(str_repeat('a', 64));
        $zap->setKind(9735);
        $zap->setPubkey(str_repeat('b', 64));
        $zap->setContent('Nice article');
        $zap->setCreatedAt(strtotime('2026-01-10 12:00:00'));
        $zap->setTags([
            ['description', json_encode([
                'pubkey' => str_repeat('b', 64),
                'tags' => [
                    ['amount', '21000'],
                ],
            ], JSON_THROW_ON_ERROR)],
        ]);
        $zap->setSig('sig');

        $redisCacheService = $this->createMock(RedisCacheService::class);
        $redisCacheService->method('getMetadata')->willReturn(new UserMetadata());
        $redisCacheService->method('getMultipleMetadata')->willReturn([]);

        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository
            ->expects($this->once())
            ->method('findCommentsByCoordinate')
            ->with('30023:abc123:hello-world')
            ->willReturn([$zap]);

        $contextBuilder = new ContextBuilder($converter, $cache, $redisCacheService, $eventRepository);

        $context = $contextBuilder->buildPostContext($siteConfig, [], $post);
        $html = $this->renderer->render('post', $context);

        $this->assertSame(0, $context['post']['comments_count']);
        $this->assertTrue($context['post']['has_thread_activity']);
        $this->assertStringContainsString('Comments (0)', $html);
        $this->assertStringNotContainsString('Comments ()', $html);
    }

    public function testRenderPostPageShowsOneCommentCountWhenThreadContainsOnlyComments(): void
    {
        $this->renderer->setTheme('default');

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $cacheItem->method('set')->willReturnSelf();
        $cacheItem->method('expiresAfter')->willReturnSelf();

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($cacheItem);
        $cache->method('save')->willReturn(true);

        $converter = $this->createMock(MarkdownConverterInterface::class);
        $converter->method('convertToHTML')->willReturn('Content');

        $siteConfig = new SiteConfig(
            naddr: 'naddr1qqxnzd3cxqmrzv3exgmr2wfeqgsxu35yyt0mwjjh8pcz4zprhxegz69t4wr9t74vk6zne58wzh0waycrqsqqqa28pjfdhz',
            title: 'Demo Magazine',
            description: 'A demonstration magazine',
            logo: null,
            categories: [],
            pubkey: 'abc123def456',
            theme: 'default',
        );

        $post = new PostData(
            slug: 'hello-world',
            title: 'Hello World: Welcome to Our Magazine',
            summary: 'This is the first article in our brand new magazine.',
            content: 'Content',
            image: null,
            publishedAt: strtotime('2026-01-09 10:30:00'),
            pubkey: 'abc123def456',
            coordinate: '30023:abc123:hello-world',
        );

        $comment = new Event();
        $comment->setId(str_repeat('c', 64));
        $comment->setKind(1111);
        $comment->setPubkey(str_repeat('d', 64));
        $comment->setContent('Great write-up');
        $comment->setCreatedAt(strtotime('2026-01-10 13:00:00'));
        $comment->setTags([]);
        $comment->setSig('sig');

        $redisCacheService = $this->createMock(RedisCacheService::class);
        $redisCacheService->method('getMetadata')->willReturn(new UserMetadata());
        $redisCacheService->method('getMultipleMetadata')->willReturn([]);

        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository
            ->expects($this->once())
            ->method('findCommentsByCoordinate')
            ->with('30023:abc123:hello-world')
            ->willReturn([$comment]);

        $contextBuilder = new ContextBuilder($converter, $cache, $redisCacheService, $eventRepository);

        $context = $contextBuilder->buildPostContext($siteConfig, [], $post);
        $html = $this->renderer->render('post', $context);

        $this->assertSame(1, $context['post']['comments_count']);
        $this->assertTrue($context['post']['has_thread_activity']);
        $this->assertStringContainsString('Comments (1)', $html);
        $this->assertStringNotContainsString('Comments (0)', $html);
    }

    public function testRenderPostPageShowsNonZapCountWhenThreadContainsCommentAndZap(): void
    {
        $this->renderer->setTheme('default');

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $cacheItem->method('set')->willReturnSelf();
        $cacheItem->method('expiresAfter')->willReturnSelf();

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($cacheItem);
        $cache->method('save')->willReturn(true);

        $converter = $this->createMock(MarkdownConverterInterface::class);
        $converter->method('convertToHTML')->willReturn('Content');

        $siteConfig = new SiteConfig(
            naddr: 'naddr1qqxnzd3cxqmrzv3exgmr2wfeqgsxu35yyt0mwjjh8pcz4zprhxegz69t4wr9t74vk6zne58wzh0waycrqsqqqa28pjfdhz',
            title: 'Demo Magazine',
            description: 'A demonstration magazine',
            logo: null,
            categories: [],
            pubkey: 'abc123def456',
            theme: 'default',
        );

        $post = new PostData(
            slug: 'hello-world',
            title: 'Hello World: Welcome to Our Magazine',
            summary: 'This is the first article in our brand new magazine.',
            content: 'Content',
            image: null,
            publishedAt: strtotime('2026-01-09 10:30:00'),
            pubkey: 'abc123def456',
            coordinate: '30023:abc123:hello-world',
        );

        $comment = new Event();
        $comment->setId(str_repeat('e', 64));
        $comment->setKind(1111);
        $comment->setPubkey(str_repeat('f', 64));
        $comment->setContent('Interesting perspective');
        $comment->setCreatedAt(strtotime('2026-01-10 11:00:00'));
        $comment->setTags([]);
        $comment->setSig('sig');

        $zap = new Event();
        $zap->setId(str_repeat('a', 64));
        $zap->setKind(9735);
        $zap->setPubkey(str_repeat('b', 64));
        $zap->setContent('Nice article');
        $zap->setCreatedAt(strtotime('2026-01-10 12:00:00'));
        $zap->setTags([
            ['description', json_encode([
                'pubkey' => str_repeat('b', 64),
                'tags' => [
                    ['amount', '21000'],
                ],
            ], JSON_THROW_ON_ERROR)],
        ]);
        $zap->setSig('sig');

        $redisCacheService = $this->createMock(RedisCacheService::class);
        $redisCacheService->method('getMetadata')->willReturn(new UserMetadata());
        $redisCacheService->method('getMultipleMetadata')->willReturn([]);

        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository
            ->expects($this->once())
            ->method('findCommentsByCoordinate')
            ->with('30023:abc123:hello-world')
            ->willReturn([$comment, $zap]);

        $contextBuilder = new ContextBuilder($converter, $cache, $redisCacheService, $eventRepository);

        $context = $contextBuilder->buildPostContext($siteConfig, [], $post);
        $html = $this->renderer->render('post', $context);

        $this->assertSame(1, $context['post']['comments_count']);
        $this->assertTrue($context['post']['has_thread_activity']);
        $this->assertStringContainsString('Comments (1)', $html);
        $this->assertStringNotContainsString('Comments (2)', $html);
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

