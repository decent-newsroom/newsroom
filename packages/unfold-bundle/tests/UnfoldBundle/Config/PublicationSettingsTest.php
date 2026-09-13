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
