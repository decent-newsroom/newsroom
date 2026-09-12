<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Content;

use DecentNewsroom\UnfoldBundle\Cache\StaleWhileRevalidateCache;
use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Content\ContentProvider;
use DecentNewsroom\UnfoldBundle\Contract\EventReadGatewayInterface;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use DecentNewsroom\UnfoldBundle\Contract\PublicationTreeLookupInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ContentProviderPublicationPostsTest extends TestCase
{
    public function testPublicationPostsAreDeduplicatedSortedAndCapped(): void
    {
        $first = $this->postEvent('first', 100);
        $duplicate = $this->postEvent('first', 200);
        $second = $this->postEvent('second', 200);
        $tree = $this->createMock(PublicationTreeLookupInterface::class);
        $tree->expects(self::exactly(3))
            ->method('findChildren')
            ->willReturnCallback(
                fn(string $coordinate): array => match ($coordinate) {
                    '30040:owner:publication' => [
                        $this->categoryEvent('news'),
                        $this->categoryEvent('features'),
                    ],
                    '30040:owner:news' => [$first, $duplicate],
                    '30040:owner:features' => [$second],
                    default => [],
                }
            );

        $provider = new ContentProvider(
            eventGateway: $this->createMock(EventReadGatewayInterface::class),
            swrCache: new StaleWhileRevalidateCache(new ArrayAdapter(), new NullLogger()),
            logger: new NullLogger(),
            treeLookup: $tree,
        );

        $posts = $provider->getPublicationPosts(
            new SiteConfig('30040:owner:publication', 'Publication', '', null, [
                '30040:owner:news',
                '30040:owner:features',
            ], 'owner'),
            2,
        );

        self::assertCount(2, $posts);
        self::assertSame('second', $posts[0]->slug);
        self::assertSame('first', $posts[1]->slug);
        self::assertSame(100, $posts[1]->publishedAt);
    }

    private function categoryEvent(string $slug): NostrEvent
    {
        return new NostrEvent(
            id: $slug,
            pubkey: 'owner',
            kind: 30040,
            content: '',
            tags: [['d', $slug], ['title', $slug]],
            createdAt: 1,
            sig: 'signature',
        );
    }

    private function postEvent(string $slug, int $publishedAt): NostrEvent
    {
        return new NostrEvent(
            id: $slug,
            pubkey: 'author',
            kind: 30023,
            content: '',
            tags: [['d', $slug], ['title', $slug], ['published_at', (string) $publishedAt]],
            createdAt: $publishedAt,
            sig: 'signature',
        );
    }
}
