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
        return $this->store->find($coordinate) ?? new PublicationSettings($coordinate);
    }

    public function resolve(string $coordinate, ?string $theme = null): PublicationSettings
    {
        $settings = $theme === null ? $this->get($coordinate) : new PublicationSettings($coordinate, $theme);
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
}
