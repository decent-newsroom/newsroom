<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Config;

use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettingsManager;
use DecentNewsroom\UnfoldBundle\Config\SiteConfigLoader;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSettingsStoreInterface;
use DecentNewsroom\UnfoldBundle\Theme\HandlebarsRenderer;
use PHPUnit\Framework\TestCase;

final class AboutArticleSettingsTest extends TestCase
{
    private const PUBLICATION = '30040:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa:root';
    private const ARTICLE = '30023:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb:about';

    public function testThemeAndFooterChangesPreserveAboutSelection(): void
    {
        $settings = new PublicationSettings(self::PUBLICATION, 'default', [], self::ARTICLE, ['wss://relay.example']);
        self::assertSame(self::ARTICLE, $settings->withTheme('paper')->aboutArticleCoordinate);
        self::assertSame(['wss://relay.example'], $settings->withFooterLinks([
            ['label' => 'Support', 'url' => 'https://example.com/support'],
        ])->aboutRelayHints);
    }

    public function testManagerUpdatesAndClearsAboutSelectionWithPresentation(): void
    {
        $initial = new PublicationSettings(self::PUBLICATION, 'default', [], self::ARTICLE, ['wss://relay.example']);
        $saved = [];
        $store = $this->createMock(PublicationSettingsStoreInterface::class);
        $store->method('find')->willReturnCallback(static function () use (&$saved, $initial): PublicationSettings { return $saved === [] ? $initial : $saved[array_key_last($saved)]; });
        $store->method('save')->willReturnCallback(static function (PublicationSettings $settings) use (&$saved): void { $saved[] = $settings; });
        $renderer = $this->createMock(HandlebarsRenderer::class);
        $renderer->method('getAvailableThemes')->willReturn(['default', 'paper']);
        $loader = $this->createMock(SiteConfigLoader::class);
        $manager = new PublicationSettingsManager($store, $renderer, $loader);

        $manager->saveTheme(self::PUBLICATION, 'paper');
        self::assertSame(self::ARTICLE, $saved[0]->aboutArticleCoordinate);
        $manager->savePresentation(self::PUBLICATION, 'paper', []);
        self::assertSame(self::ARTICLE, $saved[1]->aboutArticleCoordinate);
        self::assertSame(['wss://relay.example'], $saved[1]->aboutRelayHints);
        $manager->savePresentation(self::PUBLICATION, 'paper', [], null, [], true);
        self::assertNull($saved[2]->aboutArticleCoordinate);
        self::assertSame([], $saved[2]->aboutRelayHints);
    }

    public function testInvalidAboutReferenceDoesNotSave(): void
    {
        $store = $this->createMock(PublicationSettingsStoreInterface::class);
        $store->expects(self::never())->method('save');
        $renderer = $this->createMock(HandlebarsRenderer::class);
        $renderer->method('getAvailableThemes')->willReturn(['default']);
        $loader = $this->createMock(SiteConfigLoader::class);
        $loader->expects(self::never())->method('invalidateFromCoordinate');
        $manager = new PublicationSettingsManager($store, $renderer, $loader);

        $this->expectException(\InvalidArgumentException::class);
        $manager->savePresentation(self::PUBLICATION, 'default', [], '30040:' . str_repeat('b', 64) . ':about', [], true);
    }
}
