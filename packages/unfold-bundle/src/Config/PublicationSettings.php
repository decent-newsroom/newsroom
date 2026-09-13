<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Config;

/** Local presentation settings for one permanently identified publication. */
final readonly class PublicationSettings
{
    public string $coordinate;

    public function __construct(string $coordinate, public string $theme = 'default')
    {
        $this->coordinate = self::normalizeCoordinate($coordinate);
        if (strlen($theme) > 255 || preg_match('/^[a-zA-Z0-9_-]+$/D', $theme) !== 1) {
            throw new \InvalidArgumentException('unfold_setup.invalid_theme');
        }
    }

    public static function normalizeCoordinate(string $coordinate): string
    {
        $coordinate = trim($coordinate);
        if (strlen($coordinate) > 500 || preg_match('/^30040:([a-fA-F0-9]{64}):(.+)$/D', $coordinate, $matches) !== 1) {
            throw new \InvalidArgumentException('unfold_setup.invalid_coordinate');
        }

        return '30040:' . strtolower($matches[1]) . ':' . $matches[2];
    }
}
