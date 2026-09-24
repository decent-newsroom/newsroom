<?php

declare(strict_types=1);

namespace App\Tests\Unfold;

use App\Entity\UnfoldSite;
use App\Repository\UnfoldSiteRepository;
use App\Unfold\UnfoldSetupService;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;
use DecentNewsroom\UnfoldBundle\Config\SiteConfigLoader;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSettingsStoreInterface;
use DecentNewsroom\UnfoldBundle\Theme\HandlebarsRenderer;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class UnfoldSetupServiceTest extends TestCase
{
    private const COORDINATE = '30040:' . 'a234567890123456789012345678901234567890123456789012345678901234' . ':main';
    private const OTHER_COORDINATE = '30040:' . 'b234567890123456789012345678901234567890123456789012345678901234' . ':other';

    public function testCreateWithoutThemePreservesExistingSettings(): void
    {
        $sites = $this->createMock(UnfoldSiteRepository::class);
        $sites->expects(self::once())->method('findBySubdomain')->with('magazine')->willReturn(null);

        $existingSettings = new PublicationSettings(self::COORDINATE, 'casper', [['label' => 'Owner', 'url' => 'https://owner.example']]);
        $settings = $this->createMock(PublicationSettingsStoreInterface::class);
        $settings->expects(self::once())->method('find')->with(self::COORDINATE)->willReturn($existingSettings);
        $settings->expects(self::once())->method('save')->with($existingSettings);

        $entityManager = $this->transactionalEntityManager();
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(UnfoldSite::class));

        $loader = $this->createMock(SiteConfigLoader::class);
        $loader->expects(self::once())->method('invalidateFromCoordinate')->with(self::COORDINATE);

        $service = $this->service($sites, $settings, $entityManager, $loader, ['default', 'casper']);

        $site = $service->create(' MAGAZINE ', self::COORDINATE);

        self::assertSame('magazine', $site->getSubdomain());
        self::assertSame(self::COORDINATE, $site->getCoordinate());
        self::assertSame('casper', $existingSettings->theme);
    }

    public function testDuplicateWithSameCoordinateIsIdempotent(): void
    {
        $site = (new UnfoldSite())
            ->setSubdomain('magazine')
            ->setCoordinate(self::COORDINATE);

        $sites = $this->createMock(UnfoldSiteRepository::class);
        $sites->expects(self::once())->method('findBySubdomain')->with('magazine')->willReturn($site);

        $existingLinks = [['label' => 'Owner', 'url' => 'https://owner.example']];
        $settings = $this->createMock(PublicationSettingsStoreInterface::class);
        $settings->expects(self::once())->method('find')->with(self::COORDINATE)
            ->willReturn(new PublicationSettings(self::COORDINATE, 'default', $existingLinks));
        $settings->expects(self::once())
            ->method('save')
            ->with(self::callback(
                static fn (PublicationSettings $value): bool => $value->coordinate === self::COORDINATE
                    && $value->theme === 'casper'
                    && $value->footerLinks === $existingLinks
            ));

        $entityManager = $this->transactionalEntityManager();
        $entityManager->expects(self::once())->method('persist')->with($site);

        $loader = $this->createMock(SiteConfigLoader::class);
        $loader->expects(self::once())->method('invalidateFromCoordinate')->with(self::COORDINATE);

        $service = $this->service($sites, $settings, $entityManager, $loader, ['default', 'casper']);

        self::assertSame($site, $service->create('magazine', self::COORDINATE, 'casper'));
        self::assertSame(self::COORDINATE, $site->getCoordinate());
    }

    public function testDuplicateWithConflictingCoordinateIsRejectedBeforeWrites(): void
    {
        $site = (new UnfoldSite())
            ->setSubdomain('magazine')
            ->setCoordinate(self::COORDINATE);

        $sites = $this->createMock(UnfoldSiteRepository::class);
        $sites->expects(self::once())->method('findBySubdomain')->with('magazine')->willReturn($site);

        $settings = $this->createMock(PublicationSettingsStoreInterface::class);
        $settings->expects(self::never())->method('find');
        $settings->expects(self::never())->method('save');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('flush');
        $entityManager->expects(self::never())->method('wrapInTransaction');
        $entityManager->expects(self::never())->method('persist');

        $service = $this->service(
            $sites,
            $settings,
            $entityManager,
            $this->createMock(SiteConfigLoader::class),
            ['default', 'casper'],
        );

        $this->expectExceptionObject(new \InvalidArgumentException('unfold_setup.subdomain_taken'));
        $service->create('magazine', self::OTHER_COORDINATE);
    }

    public function testUpdateCannotChangePublicationIdentity(): void
    {
        $site = (new UnfoldSite())
            ->setSubdomain('magazine')
            ->setCoordinate(self::COORDINATE);

        $sites = $this->createMock(UnfoldSiteRepository::class);
        $sites->expects(self::once())->method('findBySubdomain')->with('updated')->willReturn(null);

        $settings = $this->createMock(PublicationSettingsStoreInterface::class);
        $settings->expects(self::once())
            ->method('save')
            ->with(self::callback(
                static fn (PublicationSettings $value): bool => $value->coordinate === self::COORDINATE
                    && $value->theme === 'casper'
            ));

        $entityManager = $this->transactionalEntityManager();
        $entityManager->expects(self::never())->method('persist');

        $loader = $this->createMock(SiteConfigLoader::class);
        $loader->expects(self::once())->method('invalidateFromCoordinate')->with(self::COORDINATE);

        $service = $this->service($sites, $settings, $entityManager, $loader, ['default', 'casper']);

        $service->update($site, 'updated', 'casper');

        self::assertSame('updated', $site->getSubdomain());
        self::assertSame(self::COORDINATE, $site->getCoordinate());
    }

    public function testInvalidSubdomainIsRejectedBeforeRepositoryAndWrites(): void
    {
        $sites = $this->createMock(UnfoldSiteRepository::class);
        $sites->expects(self::never())->method('findBySubdomain');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('flush');
        $entityManager->expects(self::never())->method('wrapInTransaction');

        $service = $this->service(
            $sites,
            $this->createMock(PublicationSettingsStoreInterface::class),
            $entityManager,
            $this->createMock(SiteConfigLoader::class),
            ['default'],
        );

        $this->expectExceptionObject(new \InvalidArgumentException('unfold_setup.invalid_subdomain'));
        $service->create('bad domain', self::COORDINATE, 'default');
    }

    public function testInvalidCoordinateIsRejectedBeforeRepositoryAndWrites(): void
    {
        $sites = $this->createMock(UnfoldSiteRepository::class);
        $sites->expects(self::never())->method('findBySubdomain');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('flush');
        $entityManager->expects(self::never())->method('wrapInTransaction');

        $service = $this->service(
            $sites,
            $this->createMock(PublicationSettingsStoreInterface::class),
            $entityManager,
            $this->createMock(SiteConfigLoader::class),
            ['default'],
        );

        $this->expectExceptionObject(new \InvalidArgumentException('unfold_setup.invalid_coordinate'));
        $service->create('magazine', 'not-a-coordinate', 'default');
    }

    public function testInvalidThemeIsRejectedBeforeWrites(): void
    {
        $sites = $this->createMock(UnfoldSiteRepository::class);
        $sites->expects(self::once())->method('findBySubdomain')->with('magazine')->willReturn(null);

        $settings = $this->createMock(PublicationSettingsStoreInterface::class);
        $settings->expects(self::never())->method('find');
        $settings->expects(self::never())->method('save');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('flush');
        $entityManager->expects(self::never())->method('wrapInTransaction');

        $service = $this->service($sites, $settings, $entityManager, $this->createMock(SiteConfigLoader::class), ['default']);

        $this->expectExceptionObject(new \InvalidArgumentException('unfold_setup.invalid_theme'));
        $service->create('magazine', self::COORDINATE, 'missing');
    }

    private function service(
        UnfoldSiteRepository $sites,
        PublicationSettingsStoreInterface $settings,
        EntityManagerInterface $entityManager,
        SiteConfigLoader $loader,
        array $themes,
    ): UnfoldSetupService {
        $renderer = $this->createMock(HandlebarsRenderer::class);
        $renderer->method('getAvailableThemes')->willReturn($themes);

        return new UnfoldSetupService($sites, $settings, $entityManager, $loader,
            new \DecentNewsroom\UnfoldBundle\Config\PublicationSettingsManager($settings, $renderer, $loader));
    }

    private function transactionalEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('flush');
        $entityManager->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());

        return $entityManager;
    }
}



