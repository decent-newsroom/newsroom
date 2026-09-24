<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Config;

use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettingsManager;
use DecentNewsroom\UnfoldBundle\Config\SiteConfigLoader;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSettingsStoreInterface;
use DecentNewsroom\UnfoldBundle\Theme\HandlebarsRenderer;
use PHPUnit\Framework\TestCase;

final class PublicationSettingsManagerTest extends TestCase
{
    public function testSavePersistsBeforeInvalidatingTheNormalizedCoordinate(): void
    {
        $coordinate = '30040:' . str_repeat('A', 64) . ':root';
        $saved = false;
        $store = $this->createMock(PublicationSettingsStoreInterface::class);
        $store->expects(self::once())->method('save')->willReturnCallback(function (PublicationSettings $settings) use (&$saved, $coordinate): void {
            self::assertSame(strtolower($coordinate), $settings->coordinate);
            self::assertSame('paper', $settings->theme);
            $saved = true;
        });
        $loader = $this->createMock(SiteConfigLoader::class);
        $loader->expects(self::once())->method('invalidateFromCoordinate')->with(strtolower($coordinate))
            ->willReturnCallback(function () use (&$saved): void { self::assertTrue($saved); });
        $this->manager($store, $loader)->saveTheme($coordinate, 'paper');
    }

    public function testSaveThemePreservesExistingFooterLinks(): void
    {
        $coordinate = '30040:' . str_repeat('a', 64) . ':root';
        $links = [['label' => 'About', 'url' => 'https://example.com/about']];
        $store = $this->createMock(PublicationSettingsStoreInterface::class);
        $store->method('find')->willReturn(new PublicationSettings($coordinate, 'default', $links));
        $store->expects(self::once())->method('save')->with(self::callback(
            static fn (PublicationSettings $settings): bool => $settings->theme === 'paper' && $settings->footerLinks === $links
        ));
        $loader = $this->createMock(SiteConfigLoader::class);
        $this->manager($store, $loader)->saveTheme($coordinate, 'paper');
    }

    public function testSavePresentationWritesThemeAndLinksTogether(): void
    {
        $coordinate = '30040:' . str_repeat('a', 64) . ':root';
        $saved = false;
        $store = $this->createMock(PublicationSettingsStoreInterface::class);
        $store->expects(self::once())->method('save')->willReturnCallback(
            function (PublicationSettings $settings) use (&$saved): void {
                self::assertSame('paper', $settings->theme);
                self::assertSame([['label' => 'About', 'url' => 'https://example.com/about']], $settings->footerLinks);
                $saved = true;
            }
        );
        $loader = $this->createMock(SiteConfigLoader::class);
        $loader->expects(self::once())->method('invalidateFromCoordinate')->with($coordinate)
            ->willReturnCallback(function () use (&$saved): void { self::assertTrue($saved); });

        $this->manager($store, $loader)->savePresentation($coordinate, 'paper', [
            ['label' => ' About ', 'url' => 'https://example.com/about'],
        ]);
    }

    public function testInvalidPresentationDoesNotWriteOrInvalidate(): void
    {
        $store = $this->createMock(PublicationSettingsStoreInterface::class);
        $store->expects(self::never())->method('save');
        $loader = $this->createMock(SiteConfigLoader::class);
        $loader->expects(self::never())->method('invalidateFromCoordinate');
        $this->expectException(\InvalidArgumentException::class);
        $this->manager($store, $loader)->savePresentation(
            '30040:' . str_repeat('a', 64) . ':root',
            'paper',
            [['label' => 'Bad', 'url' => 'http://example.com']],
        );
    }

    public function testInvalidPresentationThemeDoesNotWriteOrInvalidate(): void
    {
        $store = $this->createMock(PublicationSettingsStoreInterface::class);
        $store->expects(self::never())->method('save');
        $loader = $this->createMock(SiteConfigLoader::class);
        $loader->expects(self::never())->method('invalidateFromCoordinate');
        $this->expectException(\InvalidArgumentException::class);
        $this->manager($store, $loader)->savePresentation(
            '30040:' . str_repeat('a', 64) . ':root',
            'missing',
            [],
        );
    }

    public function testUnsupportedThemeDoesNotWriteOrInvalidate(): void
    {
        $store = $this->createMock(PublicationSettingsStoreInterface::class);
        $store->expects(self::never())->method('save');
        $loader = $this->createMock(SiteConfigLoader::class);
        $loader->expects(self::never())->method('invalidateFromCoordinate');
        $this->expectException(\InvalidArgumentException::class);
        $this->manager($store, $loader)->saveTheme('30040:' . str_repeat('a', 64) . ':root', 'missing');
    }

    public function testStorageFailureDoesNotInvalidate(): void
    {
        $store = $this->createMock(PublicationSettingsStoreInterface::class);
        $store->method('save')->willThrowException(new \RuntimeException('offline'));
        $loader = $this->createMock(SiteConfigLoader::class);
        $loader->expects(self::never())->method('invalidateFromCoordinate');
        $this->expectException(\RuntimeException::class);
        $this->manager($store, $loader)->saveTheme('30040:' . str_repeat('a', 64) . ':root', 'paper');
    }

    private function manager(PublicationSettingsStoreInterface $store, SiteConfigLoader $loader): PublicationSettingsManager
    {
        $renderer = $this->createMock(HandlebarsRenderer::class);
        $renderer->method('getAvailableThemes')->willReturn(['default', 'paper']);
        return new PublicationSettingsManager($store, $renderer, $loader);
    }
}
