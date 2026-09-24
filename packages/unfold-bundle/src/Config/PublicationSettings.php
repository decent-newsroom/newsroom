<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Config;

/** Local presentation settings for one permanently identified publication. */
final readonly class PublicationSettings
{
    public string $coordinate;
    /** @var list<array{label: string, url: string}> */
    public array $footerLinks;

    /** @param array<mixed> $footerLinks */
    public function __construct(string $coordinate, public string $theme = 'default', array $footerLinks = [])
    {
        $this->coordinate = self::normalizeCoordinate($coordinate);
        if (strlen($theme) > 255 || preg_match('/^[a-zA-Z0-9_-]+$/D', $theme) !== 1) {
            throw new \InvalidArgumentException('unfold_setup.invalid_theme');
        }
        $this->footerLinks = self::normalizeFooterLinks($footerLinks);
    }

    public function withTheme(string $theme): self
    {
        return new self($this->coordinate, $theme, $this->footerLinks);
    }

    /** @param array<mixed> $footerLinks */
    public function withFooterLinks(array $footerLinks): self
    {
        return new self($this->coordinate, $this->theme, $footerLinks);
    }

    public static function normalizeCoordinate(string $coordinate): string
    {
        $coordinate = trim($coordinate);
        if (strlen($coordinate) > 500 || preg_match('/^30040:([a-fA-F0-9]{64}):(.+)$/D', $coordinate, $matches) !== 1) {
            throw new \InvalidArgumentException('unfold_setup.invalid_coordinate');
        }

        return '30040:' . strtolower($matches[1]) . ':' . $matches[2];
    }

    /**
     * @param array<mixed> $footerLinks
     * @return list<array{label: string, url: string}>
     */
    private static function normalizeFooterLinks(array $footerLinks): array
    {
        if (count($footerLinks) > 5) {
            throw new \InvalidArgumentException('unfold_setup.invalid_footer_links');
        }

        $normalized = [];
        foreach ($footerLinks as $link) {
            if (!is_array($link) || !isset($link['label'], $link['url']) || !is_string($link['label']) || !is_string($link['url'])) {
                throw new \InvalidArgumentException('unfold_setup.invalid_footer_links');
            }

            $label = trim($link['label']);
            $url = $link['url'];
            if ($label === '' || preg_match('/[\x00-\x1F\x7F]/', $label) === 1
                || preg_match('/^.{1,80}$/usD', $label) !== 1
                || strlen($url) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $url) === 1
                || filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw new \InvalidArgumentException('unfold_setup.invalid_footer_links');
            }

            $parts = parse_url($url);
            if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https'
                || !isset($parts['host']) || $parts['host'] === ''
                || array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
                throw new \InvalidArgumentException('unfold_setup.invalid_footer_links');
            }

            $normalized[] = ['label' => $label, 'url' => $url];
        }

        return $normalized;
    }
}
