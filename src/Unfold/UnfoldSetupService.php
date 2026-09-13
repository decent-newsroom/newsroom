<?php

declare(strict_types=1);

namespace App\Unfold;

use App\Entity\UnfoldSite;
use App\Repository\UnfoldSiteRepository;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;
use DecentNewsroom\UnfoldBundle\Config\SiteConfigLoader;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSettingsStoreInterface;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettingsManager;
use Doctrine\ORM\EntityManagerInterface;

/** Shared local setup. Callers retain owner/operator/billing access checks. */
class UnfoldSetupService
{
    public function __construct(
        private readonly UnfoldSiteRepository $sites,
        private readonly PublicationSettingsStoreInterface $settings,
        private readonly EntityManagerInterface $entityManager,
        private readonly SiteConfigLoader $configLoader,
        private readonly PublicationSettingsManager $settingsManager,
    ) {}

    /** A retry reuses the same mapping; omitted theme preserves existing settings. */
    public function create(string $subdomain, string $coordinate, ?string $theme = null): UnfoldSite
    {
        $subdomain = $this->normalizeSubdomain($subdomain);
        $coordinate = PublicationSettings::normalizeCoordinate($coordinate);
        $site = $this->sites->findBySubdomain($subdomain);
        if ($site !== null && PublicationSettings::normalizeCoordinate($site->getCoordinate()) !== $coordinate) {
            throw new \InvalidArgumentException('unfold_setup.subdomain_taken');
        }

        $settings = $this->resolveSettings($coordinate, $theme);
        $site ??= (new UnfoldSite())->setSubdomain($subdomain)->setCoordinate($coordinate);

        $this->entityManager->wrapInTransaction(function () use ($site, $settings): void {
            $this->entityManager->persist($site);
            $this->settings->save($settings);
        });
        $this->configLoader->invalidateFromCoordinate($coordinate);

        return $site;
    }

    public function update(UnfoldSite $site, string $subdomain, string $theme): void
    {
        $subdomain = $this->normalizeSubdomain($subdomain);
        $existing = $this->sites->findBySubdomain($subdomain);
        if ($existing !== null && $existing !== $site) {
            throw new \InvalidArgumentException('unfold_setup.subdomain_taken');
        }
        $settings = $this->resolveSettings($site->getCoordinate(), $theme);

        $this->entityManager->wrapInTransaction(function () use ($site, $subdomain, $settings): void {
            $site->setSubdomain($subdomain);
            $this->settings->save($settings);
        });
        $this->configLoader->invalidateFromCoordinate($settings->coordinate);
    }

    public function getSettings(string $coordinate): PublicationSettings
    {
        return $this->settingsManager->get($coordinate);
    }

    private function resolveSettings(string $coordinate, ?string $theme): PublicationSettings
    {
        return $this->settingsManager->resolve($coordinate, $theme);
    }

    private function normalizeSubdomain(string $subdomain): string
    {
        $subdomain = strtolower(trim($subdomain));
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $subdomain) !== 1
            || in_array($subdomain, ['relay', 'www', 'api', 'admin', 'mail', 'smtp', 'imap', 'pop', 'ftp', 'cdn', 'static', 'assets'], true)) {
            throw new \InvalidArgumentException('unfold_setup.invalid_subdomain');
        }

        return $subdomain;
    }
}
