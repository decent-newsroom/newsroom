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

final class ContentProviderContractTest extends TestCase
{
    public function testCategoriesUseTheTreeContractAndPreserveConfiguredOrder(): void
    {
        $first = $this->event('first', 'First');
        $second = $this->event('second', 'Second');
        $tree = $this->createMock(PublicationTreeLookupInterface::class);
        $tree->expects(self::once())
            ->method('findChildren')
            ->with('30040:owner:magazine')
            ->willReturn([$second, $first]);

        $gateway = $this->createMock(EventReadGatewayInterface::class);
        $gateway->expects(self::never())->method('findByCoordinates');

        $provider = new ContentProvider(
            eventGateway: $gateway,
            swrCache: new StaleWhileRevalidateCache(new ArrayAdapter(), new NullLogger()),
            logger: new NullLogger(),
            treeLookup: $tree,
        );

        $categories = $provider->getCategories(new SiteConfig(
            naddr: '30040:owner:magazine',
            title: 'Magazine',
            description: '',
            logo: null,
            categories: ['30040:owner:first', '30040:owner:second'],
            pubkey: 'owner',
        ));

        self::assertSame(['First', 'Second'], array_map(
            static fn($category): string => $category->title,
            $categories,
        ));
    }

    private function event(string $identifier, string $title): NostrEvent
    {
        return new NostrEvent(
            id: $identifier,
            pubkey: 'owner',
            kind: 30040,
            content: '',
            tags: [
                ['d', $identifier],
                ['title', $title],
            ],
            createdAt: 1,
            sig: 'signature',
        );
    }
}
