<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Config;

/** Local presentation settings for one permanently identified publication. */
final readonly class PublicationSettings
{
    public string $coordinate;
    /** @var list<array{label: string, url: string}> */
    public array $footerLinks;
    public ?string $aboutArticleCoordinate;
    /** @var list<string> */
    public array $aboutRelayHints;

    /**
     * @param array<mixed> $footerLinks
     * @param list<string> $aboutRelayHints
     */
    public function __construct(
        string $coordinate,
        public string $theme = 'default',
        array $footerLinks = [],
        ?string $aboutArticleCoordinate = null,
        array $aboutRelayHints = [],
    ) {
        $this->coordinate = self::normalizeCoordinate($coordinate);
        if (strlen($theme) > 255 || preg_match('/^[a-zA-Z0-9_-]+$/D', $theme) !== 1) {
            throw new \InvalidArgumentException('unfold_setup.invalid_theme');
        }
        $this->footerLinks = self::normalizeFooterLinks($footerLinks);
        $this->aboutArticleCoordinate = $aboutArticleCoordinate === null
            ? null : AboutArticleReference::fromInput($aboutArticleCoordinate)->coordinate;
        if ($this->aboutArticleCoordinate === null && $aboutRelayHints !== []) {
            throw new \InvalidArgumentException('unfold_setup.invalid_about_article');
        }
        $this->aboutRelayHints = self::normalizeRelayHints($aboutRelayHints);
    }

    public function withTheme(string $theme): self
    {
        return new self($this->coordinate, $theme, $this->footerLinks, $this->aboutArticleCoordinate, $this->aboutRelayHints);
    }

    /** @param array<mixed> $footerLinks */
    public function withFooterLinks(array $footerLinks): self
    {
        return new self($this->coordinate, $this->theme, $footerLinks, $this->aboutArticleCoordinate, $this->aboutRelayHints);
    }

    /** @param list<string> $relayHints */
    public function withAboutArticle(?string $coordinate, array $relayHints = []): self
    {
        return new self($this->coordinate, $this->theme, $this->footerLinks, $coordinate, $relayHints);
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

    /**
     * @param list<string> $relayHints
     * @return list<string>
     */
    private static function normalizeRelayHints(array $relayHints): array
    {
        if (count($relayHints) > 5) {
            throw new \InvalidArgumentException('unfold_setup.invalid_about_article');
        }
        $normalized = [];
        foreach ($relayHints as $relay) {
            if (strlen($relay) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $relay) === 1) {
                throw new \InvalidArgumentException('unfold_setup.invalid_about_article');
            }
            $parts = parse_url($relay);
            if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['ws', 'wss'], true)
                || !isset($parts['host']) || $parts['host'] === ''
                || isset($parts['user']) || isset($parts['pass'])) {
                throw new \InvalidArgumentException('unfold_setup.invalid_about_article');
            }
            if (!in_array($relay, $normalized, true)) {
                $normalized[] = $relay;
            }
        }
        return $normalized;
    }
}
