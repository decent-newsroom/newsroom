<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Config;

use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;
use PHPUnit\Framework\TestCase;

final class PublicationSettingsTest extends TestCase
{
    public function testNormalizesOnlyThePubkeyInAFullCoordinate(): void
    {
        $pubkey = str_repeat('AB', 32);
        $settings = new PublicationSettings('30040:' . $pubkey . ':Magazine:Draft', 'default');

        self::assertSame('30040:' . strtolower($pubkey) . ':Magazine:Draft', $settings->coordinate);
        self::assertSame('default', $settings->theme);
    }

    public function testNormalizesFooterLinksAndPreservesThemAcrossThemeChanges(): void
    {
        $coordinate = '30040:' . str_repeat('a', 64) . ':magazine';
        $settings = new PublicationSettings($coordinate, 'default', [
            ['label' => '  About  ', 'url' => 'https://example.com/about'],
        ]);

        self::assertSame([['label' => 'About', 'url' => 'https://example.com/about']], $settings->footerLinks);
        self::assertSame($settings->footerLinks, $settings->withTheme('paper')->footerLinks);
        self::assertSame('default', $settings->withFooterLinks([])->theme);
        self::assertSame([], $settings->withFooterLinks([])->footerLinks);
    }

    /** @dataProvider invalidFooterLinks */
    public function testRejectsInvalidFooterLinks(array $links): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unfold_setup.invalid_footer_links');

        new PublicationSettings('30040:' . str_repeat('a', 64) . ':magazine', 'default', $links);
    }

    /** @return iterable<string, array{0: array<mixed>}> */
    public static function invalidFooterLinks(): iterable
    {
        yield 'more than five' => [array_fill(0, 6, ['label' => 'Link', 'url' => 'https://example.com'])];
        yield 'blank label' => [[['label' => '  ', 'url' => 'https://example.com']]];
        yield 'long label' => [[['label' => str_repeat('a', 81), 'url' => 'https://example.com']]];
        yield 'label control' => [[['label' => "A\nB", 'url' => 'https://example.com']]];
        yield 'http URL' => [[['label' => 'Link', 'url' => 'http://example.com']]];
        yield 'relative URL' => [[['label' => 'Link', 'url' => '/about']]];
        yield 'credentials' => [[['label' => 'Link', 'url' => 'https://user:pass@example.com']]];
        yield 'URL control' => [[['label' => 'Link', 'url' => "https://example.com/\n"]]];
        yield 'long URL' => [[['label' => 'Link', 'url' => 'https://example.com/' . str_repeat('a', 2049)]]];
        yield 'missing URL' => [[['label' => 'Link']]];
    }

    /** @dataProvider invalidCoordinates */
    public function testRejectsInvalidCoordinates(string $coordinate): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unfold_setup.invalid_coordinate');

        new PublicationSettings($coordinate);
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidCoordinates(): iterable
    {
        $pubkey = str_repeat('a', 64);

        yield 'wrong kind' => ['30023:' . $pubkey . ':magazine'];
        yield 'short pubkey' => ['30040:abc123:magazine'];
        yield 'empty d tag' => ['30040:' . $pubkey . ':'];
    }

    /** @dataProvider invalidThemes */
    public function testRejectsThemePathTraversal(string $theme): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unfold_setup.invalid_theme');

        new PublicationSettings('30040:' . str_repeat('a', 64) . ':magazine', $theme);
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidThemes(): iterable
    {
        yield 'parent traversal' => ['../default'];
        yield 'absolute path' => ['/tmp/theme'];
        yield 'nested traversal' => ['default/../../outside'];
    }
}
