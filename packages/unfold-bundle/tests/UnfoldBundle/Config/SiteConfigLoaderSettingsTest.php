<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Config;

use DecentNewsroom\UnfoldBundle\Cache\StaleWhileRevalidateCache;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;
use DecentNewsroom\UnfoldBundle\Config\SiteConfigLoader;
use DecentNewsroom\UnfoldBundle\Contract\EventReadGatewayInterface;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSettingsStoreInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class SiteConfigLoaderSettingsTest extends TestCase
{
    public function testSettingsAreAppliedAfterTheCachedRootEventOnEveryRead(): void
    {
        $coordinate = '30040:' . str_repeat('AB', 32) . ':Magazine:Draft';
        $normalizedCoordinate = '30040:' . str_repeat('ab', 32) . ':Magazine:Draft';
        $theme = 'first';
        $links = [['label' => 'First', 'url' => 'https://example.com/first']];

        $settings = $this->createMock(PublicationSettingsStoreInterface::class);
        $settings->expects(self::exactly(2))
            ->method('find')
            ->with($normalizedCoordinate)
            ->willReturnCallback(function () use ($normalizedCoordinate, &$theme, &$links): PublicationSettings {
                return new PublicationSettings($normalizedCoordinate, $theme, $links);
            });

        $gateway = $this->createMock(EventReadGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('findByCoordinate')
            ->with($normalizedCoordinate, [])
            ->willReturn($this->rootEvent($coordinate));
        $gateway->expects(self::never())->method('findByCoordinates');

        $loader = $this->loader($gateway, $settings);

        $first = $loader->loadFromCoordinate($coordinate);
        $theme = 'second';
        $links = [['label' => 'Second', 'url' => 'https://example.com/second']];
        $second = $loader->loadFromCoordinate($coordinate);

        self::assertSame('first', $first->theme);
        self::assertSame([['label' => 'First', 'url' => 'https://example.com/first']], $first->footerLinks);
        self::assertSame('second', $second->theme);
        self::assertSame($links, $second->footerLinks);
        self::assertSame('Magazine', $second->title);
    }

    public function testCoordinateLoadingDoesNotUseTheAppDataGatewayPath(): void
    {
        $coordinate = '30040:' . str_repeat('cd', 32) . ':magazine';
        $gateway = $this->createMock(EventReadGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('findByCoordinate')
            ->with($coordinate, [])
            ->willReturn($this->rootEvent($coordinate));
        $gateway->expects(self::never())->method('findByCoordinates');

        $settings = $this->createMock(PublicationSettingsStoreInterface::class);
        $settings->expects(self::once())
            ->method('find')
            ->with($coordinate)
            ->willReturn(new PublicationSettings($coordinate, 'default'));

        $config = $this->loader($gateway, $settings)->loadFromCoordinate($coordinate);

        self::assertSame($coordinate, $config->naddr);
        self::assertSame('default', $config->theme);
    }

    public function testLegacyConstructionWithoutSettingsStoreUsesTheDefaultTheme(): void
    {
        $coordinate = '30040:' . str_repeat('ef', 32) . ':magazine';
        $gateway = $this->createMock(EventReadGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('findByCoordinate')
            ->with($coordinate, [])
            ->willReturn($this->rootEvent($coordinate));

        $config = (new SiteConfigLoader(
            $gateway,
            new StaleWhileRevalidateCache(new ArrayAdapter(), new NullLogger()),
            new NullLogger(),
        ))->loadFromCoordinate($coordinate);

        self::assertSame('default', $config->theme);
    }

    private function loader(
        EventReadGatewayInterface $gateway,
        PublicationSettingsStoreInterface $settings,
    ): SiteConfigLoader {
        return new SiteConfigLoader(
            $gateway,
            new StaleWhileRevalidateCache(new ArrayAdapter(), new NullLogger()),
            new NullLogger(),
            $settings,
        );
    }

    private function rootEvent(string $coordinate): NostrEvent
    {
        [, $pubkey, $identifier] = explode(':', $coordinate, 3);
        return new NostrEvent(
            id: 'root-event',
            pubkey: strtolower($pubkey),
            kind: 30040,
            content: '',
            tags: [
                ['d', $identifier],
                ['title', 'Magazine'],
            ],
            createdAt: 1,
            sig: 'signature',
        );
    }
}
