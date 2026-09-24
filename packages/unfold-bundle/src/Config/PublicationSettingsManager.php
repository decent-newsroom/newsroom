<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Config;

use DecentNewsroom\UnfoldBundle\Contract\PublicationSettingsStoreInterface;
use DecentNewsroom\UnfoldBundle\Theme\HandlebarsRenderer;

class PublicationSettingsManager
{
    public function __construct(
        private readonly PublicationSettingsStoreInterface $store,
        private readonly HandlebarsRenderer $renderer,
        private readonly SiteConfigLoader $loader,
    ) {}

    public function get(string $coordinate): PublicationSettings
    {
        $coordinate = PublicationSettings::normalizeCoordinate($coordinate);
        return $this->store->find($coordinate) ?? new PublicationSettings($coordinate);
    }

    public function resolve(string $coordinate, ?string $theme = null): PublicationSettings
    {
        if ($theme !== null && !in_array($theme, $this->renderer->getAvailableThemes(), true)) {
            throw new \InvalidArgumentException('unfold_setup.invalid_theme');
        }

        $settings = $this->get($coordinate);
        if ($theme !== null) {
            return $settings->withTheme($theme);
        }
        if (!in_array($settings->theme, $this->renderer->getAvailableThemes(), true)) {
            throw new \InvalidArgumentException('unfold_setup.invalid_theme');
        }
        return $settings;
    }

    public function saveTheme(string $coordinate, string $theme): void
    {
        $settings = $this->resolve($coordinate, $theme);
        $this->store->save($settings);
        $this->loader->invalidateFromCoordinate($settings->coordinate);
    }

    /** @param array<mixed> $footerLinks */
    public function savePresentation(string $coordinate, string $theme, array $footerLinks): void
    {
        $settings = $this->resolve($coordinate, $theme)->withFooterLinks($footerLinks);
        $this->store->save($settings);
        $this->loader->invalidateFromCoordinate($settings->coordinate);
    }
}
