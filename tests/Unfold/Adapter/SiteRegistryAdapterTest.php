<?php

declare(strict_types=1);

namespace App\Tests\Unfold\Adapter;

use App\Entity\UnfoldSite;
use App\Repository\UnfoldSiteRepository;
use App\Unfold\SiteRegistryAdapter;
use PHPUnit\Framework\TestCase;

final class SiteRegistryAdapterTest extends TestCase
{
    public function testItMapsSitesToPublicationContracts(): void
    {
        $site = (new UnfoldSite())
            ->setSubdomain('magazine')
            ->setCoordinate('30040:ABC:main');
        $repository = $this->createMock(UnfoldSiteRepository::class);
        $repository->expects(self::once())
            ->method('findBySubdomain')
            ->with('magazine')
            ->willReturn($site);

        $publication = (new SiteRegistryAdapter($repository))->findBySubdomain('magazine');

        self::assertNotNull($publication);
        self::assertSame('magazine', $publication->subdomain);
        self::assertSame('30040:ABC:main', $publication->coordinate);
    }
}
