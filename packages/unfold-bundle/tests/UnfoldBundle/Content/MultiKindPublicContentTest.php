<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Content;

use DecentNewsroom\UnfoldBundle\Cache\StaleWhileRevalidateCache;
use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Content\AmbiguousContentException;
use DecentNewsroom\UnfoldBundle\Content\CategoryData;
use DecentNewsroom\UnfoldBundle\Content\ContentProvider;
use DecentNewsroom\UnfoldBundle\Contract\EventReadGatewayInterface;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use DecentNewsroom\UnfoldBundle\Contract\PublicationTreeLookupInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class MultiKindPublicContentTest extends TestCase
{
    private const AUTHOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const ROOT = '30040:' . self::AUTHOR . ':root';
    private const CATEGORY = '30040:' . self::AUTHOR . ':category';

    public function testMixedReferencesPreserveOrderIdentityAndFillPartialGraph(): void
    {
        $posts = [
            $this->event(30817, 'Topic:日本語/two.json'),
            $this->event(30041, 'Chapter'),
            $this->event(30818, 'wiki'),
            $this->event(30023, 'Story'),
            $this->event(30023, 'story'),
        ];
        $coordinates = array_map($this->coordinate(...), $posts);
        $category = $this->category([...$coordinates, $coordinates[0], '30024:' . self::AUTHOR . ':draft', '1:' . self::AUTHOR . ':note']);
        $data = CategoryData::fromEvent($category, self::CATEGORY);
        self::assertSame($coordinates, $data->articleCoordinates);
        $gateway = $this->createMock(EventReadGatewayInterface::class);
        $gateway->method('findByCoordinate')->with(self::CATEGORY, [])->willReturn($category);
        $gateway->expects(self::once())->method('findByCoordinates')
            ->with(array_slice($coordinates, 1))
            ->willReturn(array_combine(array_slice($coordinates, 1), array_slice($posts, 1)));
        $tree = $this->createMock(PublicationTreeLookupInterface::class);
        $tree->method('findChildren')->willReturnCallback(
            fn(string $parent): array => $parent === self::ROOT ? [$category] : [$posts[0]],
        );
        $provider = $this->provider($gateway, $tree);

        self::assertSame($coordinates, array_map(static fn($post): string => $post->coordinate, $provider->getCategoryPosts(self::CATEGORY)));
        self::assertSame([30817, 30041, 30818, 30023, 30023], array_map(static fn($post): int => $post->kind, $provider->getHomePosts($this->site(), 10)));
        self::assertSame($coordinates[0], $provider->getPostByCoordinate($coordinates[0], $this->site())?->coordinate);
        self::assertNull($provider->getPostByCoordinate('30817:' . self::OTHER . ':Topic:日本語/two.json', $this->site()));
    }

    public function testScopedGraphLeafNeverFallsBackAndWrongGatewayIdentityIsRejected(): void
    {
        $scoped = $this->event(30818, 'secret', [['s', 'members']]);
        $wanted = '30023:' . self::AUTHOR . ':exact';
        $category = $this->category([$this->coordinate($scoped), $wanted]);
        $gateway = $this->createMock(EventReadGatewayInterface::class);
        $gateway->method('findByCoordinate')->willReturn($category);
        $gateway->expects(self::once())->method('findByCoordinates')->with([$wanted])
            ->willReturn([$wanted => $this->event(30023, 'exact', [], self::OTHER)]);
        $tree = $this->createMock(PublicationTreeLookupInterface::class);
        $tree->method('findChildren')->willReturnCallback(
            static fn(string $parent): array => $parent === self::ROOT ? [$category] : [$scoped],
        );
        $provider = $this->provider($gateway, $tree);

        self::assertSame([], $provider->getHomePosts($this->site()));
        self::assertSame([], $provider->getPublicationPosts($this->site()));
        self::assertNull($provider->getPostByCoordinate($this->coordinate($scoped), $this->site()));
    }

    public function testScopedCategoryAndScopedRootStopBeforeBodiesAndGraphFallback(): void
    {
        $category = $this->category(['30023:' . self::AUTHOR . ':body'], [['s', 'private']]);
        $gateway = $this->createMock(EventReadGatewayInterface::class);
        $gateway->method('findByCoordinate')->willReturn($category);
        $gateway->expects(self::never())->method('findByCoordinates');
        $tree = $this->createMock(PublicationTreeLookupInterface::class);
        $tree->expects(self::never())->method('findChildren');
        $provider = $this->provider($gateway, $tree);
        self::assertSame([], $provider->getCategoryPosts(self::CATEGORY));
        $site = SiteConfig::fromEvent($this->event(30040, 'root', [['s', 'private']]), self::ROOT);
        self::assertTrue($site->withTheme('docs')->isScoped);
        self::assertSame([], $provider->getCategories($site));
        self::assertSame([], $provider->getPublicationPosts($site));
        self::assertNull($provider->getAboutArticle($site));
    }

    public function testLegacySlugCollisionIsExplicitAndCanonicalIdentityResolvesEachAuthor(): void
    {
        $first = $this->event(30023, 'same');
        $second = $this->event(30023, 'same', [], self::OTHER);
        $wiki = $this->event(30818, 'same');
        $posts = [$first, $second, $wiki];
        $category = $this->category(array_map($this->coordinate(...), $posts));
        $gateway = $this->createMock(EventReadGatewayInterface::class);
        $gateway->method('findByCoordinate')->willReturn($category);
        $tree = $this->createMock(PublicationTreeLookupInterface::class);
        $tree->method('findChildren')->willReturnCallback(
            static fn(string $parent): array => $parent === self::ROOT ? [$category] : $posts,
        );
        $provider = $this->provider($gateway, $tree);
        foreach ($posts as $post) {
            self::assertSame($post->kind, $provider->getPostByCoordinate($this->coordinate($post), $this->site())?->kind);
        }
        $this->expectException(AmbiguousContentException::class);
        $provider->getPost('same', $this->site());
    }

    public function testSharedCategoryInvalidationUpdatesAllPublicationAggregates(): void
    {
        $post = $this->event(30023, 'revision');
        $category = $this->category([$this->coordinate($post)]);
        $revision = 1;
        $gateway = $this->createMock(EventReadGatewayInterface::class);
        $gateway->method('findByCoordinate')->willReturnCallback(static function () use (&$category): NostrEvent {
            return $category;
        });
        $tree = $this->createMock(PublicationTreeLookupInterface::class);
        $tree->method('findChildren')->willReturnCallback(function (string $parent) use (&$category, &$revision): array {
            return str_ends_with($parent, ':category')
                ? [new NostrEvent('revision-' . $revision, self::AUTHOR, 30023, 'body-' . $revision, [['d', 'revision']], $revision, 'sig')]
                : [$category];
        });
        $cache = new ArrayAdapter();
        $provider = new ContentProvider($gateway, new StaleWhileRevalidateCache($cache, new NullLogger()), new NullLogger(), $tree);
        $otherProvider = new ContentProvider($gateway, new StaleWhileRevalidateCache($cache, new NullLogger()), new NullLogger(), $tree);
        $second = new SiteConfig('30040:' . self::OTHER . ':other-root', 'Other', '', null, [self::CATEGORY], self::OTHER);
        self::assertSame('body-1', $provider->getHomePosts($this->site())[0]->content);
        self::assertSame('body-1', $otherProvider->getPublicationPosts($second)[0]->content);
        self::assertSame('category', $otherProvider->getCategories($second)[0]->title);
        $revision = 2;
        $category = new NostrEvent('category-revised', self::AUTHOR, 30040, '', [
            ['d', 'category'], ['title', 'Updated category'], ['a', $this->coordinate($post)],
        ], 2, 'sig');
        $provider->invalidateCategoryCache(self::CATEGORY);
        self::assertSame('body-2', $provider->getHomePosts($this->site())[0]->content);
        self::assertSame('body-2', $otherProvider->getPublicationPosts($second)[0]->content);
        self::assertSame('Updated category', $provider->getCategories($this->site())[0]->title);
        self::assertSame('Updated category', $otherProvider->getCategories($second)[0]->title);
    }

    public function testMissingCategorySourceDoesNotTrustGraphBody(): void
    {
        $gateway = $this->createMock(EventReadGatewayInterface::class);
        $tree = $this->createMock(PublicationTreeLookupInterface::class);
        $tree->expects(self::never())->method('findChildren');
        self::assertSame([], $this->provider($gateway, $tree)->getCategoryPosts(self::CATEGORY));
    }

    public function testSharedNestedCategoryRevisionDoesNotRequireInvalidatingParentSnapshots(): void
    {
        $branchCoordinate = '30040:' . self::OTHER . ':branch';
        $rootCoordinate = '30040:' . self::OTHER . ':nested-root';
        $branch = $this->event(30040, 'branch', [['a', self::CATEGORY]], self::OTHER);
        $post = $this->event(30817, 'specification');
        $category = $this->category([$this->coordinate($post)]);
        $revision = 1;
        $gateway = $this->createMock(EventReadGatewayInterface::class);
        $gateway->method('findByCoordinate')->willReturnCallback(
            static fn(string $coordinate): NostrEvent => $coordinate === $branchCoordinate ? $branch : $category,
        );
        $gateway->expects(self::never())->method('findByCoordinates');
        $tree = $this->createMock(PublicationTreeLookupInterface::class);
        $tree->method('findChildren')->willReturnCallback(function (string $parent) use ($branchCoordinate, $rootCoordinate, $category, $branch, &$revision): array {
            return match ($parent) {
                $rootCoordinate => [$branch],
                self::ROOT, $branchCoordinate => [$category],
                self::CATEGORY => [new NostrEvent('spec-' . $revision, self::AUTHOR, 30817, 'revision-' . $revision, [['d', 'specification']], $revision, 'sig')],
                default => [],
            };
        });
        $provider = $this->provider($gateway, $tree);
        $nestedSite = new SiteConfig($rootCoordinate, 'Nested publication', '', null, [$branchCoordinate], self::OTHER);
        self::assertSame('revision-1', $provider->getHomePosts($this->site())[0]->content);
        self::assertSame('revision-1', $provider->getPublicationPosts($nestedSite)[0]->content);
        $revision = 2;
        $provider->invalidateCategoryCache(self::CATEGORY);
        self::assertSame('revision-2', $provider->getHomePosts($this->site())[0]->content);
        self::assertSame('revision-2', $provider->getPublicationPosts($nestedSite)[0]->content);
    }

    /** @dataProvider graphModes */
    public function testDirectRootLeavesHaveGraphGatewayParityAndSignedTraversalOrder(string $mode): void
    {
        $wiki = $this->event(30818, 'same');
        $spec = $this->event(30817, 'same');
        $article = $this->event(30023, 'about');
        $chapter = $this->event(30041, 'same');
        $descendant = $this->event(30023, 'descendant', [], self::OTHER);
        $scoped = $this->event(30817, 'secret', [['s', 'private']]);
        $draft = $this->event(30024, 'draft');
        $category = $this->category([$this->coordinate($descendant)]);
        $root = $this->event(30040, 'root', [
            ['a', $this->coordinate($wiki)], ['a', self::CATEGORY],
            ['a', $this->coordinate($spec)], ['a', $this->coordinate($article)],
            ['a', $this->coordinate($chapter)], ['a', $this->coordinate($wiki)],
            ['a', $this->coordinate($scoped)], ['a', $this->coordinate($draft)],
        ]);
        $site = SiteConfig::fromEvent($root, self::ROOT);
        self::assertSame([$this->coordinate($article)], $site->rootArticleCoordinates);
        self::assertSame($site->rootContentCoordinates, $site->withTheme('docs')->rootContentCoordinates);
        $events = [];
        foreach ([$root, $category, $wiki, $spec, $article, $chapter, $descendant, $scoped, $draft] as $event) {
            $events[$this->coordinate($event)] = $event;
        }
        $gateway = $this->createMock(EventReadGatewayInterface::class);
        $gateway->method('findByCoordinate')->willReturnCallback(static fn(string $coordinate): ?NostrEvent => $events[$coordinate] ?? null);
        if ($mode === 'full') {
            $gateway->expects(self::never())->method('findByCoordinates');
        } else {
            $gateway->method('findByCoordinates')->willReturnCallback(
                static fn(array $coordinates): array => array_intersect_key($events, array_flip($coordinates)),
            );
        }
        $tree = null;
        if ($mode !== 'gateway') {
            $tree = $this->createMock(PublicationTreeLookupInterface::class);
            $tree->method('findChildren')->willReturnCallback(
                static fn(string $parent): array => $parent === self::ROOT
                    ? ($mode === 'full' ? [$chapter, $scoped, $wiki, $spec, $article, $category, $draft] : [$wiki, $category, $scoped])
                    : [$descendant],
            );
        }
        $provider = $this->provider($gateway, $tree);
        $expected = array_map($this->coordinate(...), [$wiki, $descendant, $spec, $article, $chapter]);
        self::assertSame($expected, array_map(static fn($post): string => $post->coordinate, $provider->getPublicationInventory($site)));
        self::assertSame($expected, array_map(static fn($post): string => $post->coordinate, $provider->getHomePosts($site, 10)));
        self::assertSame($expected, array_map(static fn($post): string => $post->coordinate, $provider->getPublicationPosts($site)));
        self::assertSame($this->coordinate($spec), $provider->getPostByCoordinate($this->coordinate($spec), $site)?->coordinate);
        self::assertSame('about', $provider->getPost('about', $site)?->slug);
        self::assertSame('about', $provider->getAboutArticle($site)?->slug);
        self::assertNull($provider->getPostByCoordinate($this->coordinate($scoped), $site));
    }

    public function graphModes(): iterable
    {
        yield 'fully projected' => ['full'];
        yield 'partially projected' => ['partial'];
        yield 'gateway only' => ['gateway'];
    }

    private function event(int $kind, string $slug, array $tags = [], string $pubkey = self::AUTHOR): NostrEvent
    {
        return new NostrEvent('event-' . $slug, $pubkey, $kind, 'Body', [['d', $slug], ['title', $slug], ...$tags], 1, 'sig');
    }

    private function category(array $references, array $tags = []): NostrEvent
    {
        return $this->event(30040, 'category', [...array_map(static fn(string $coordinate): array => ['a', $coordinate], $references), ...$tags]);
    }

    private function coordinate(NostrEvent $event): string
    {
        return $event->kind . ':' . $event->pubkey . ':' . $event->tags[0][1];
    }

    private function site(): SiteConfig
    {
        return new SiteConfig(self::ROOT, 'Publication', '', null, [self::CATEGORY], self::AUTHOR);
    }

    private function provider(EventReadGatewayInterface $gateway, ?PublicationTreeLookupInterface $tree): ContentProvider
    {
        return new ContentProvider($gateway, new StaleWhileRevalidateCache(new ArrayAdapter(), new NullLogger()), new NullLogger(), $tree);
    }
}
