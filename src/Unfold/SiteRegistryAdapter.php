<?php

declare(strict_types=1);

namespace App\Unfold;

use App\Repository\UnfoldSiteRepository;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSite;
use DecentNewsroom\UnfoldBundle\Contract\SiteRegistryInterface;

final readonly class SiteRegistryAdapter implements SiteRegistryInterface
{
    public function __construct(
        private UnfoldSiteRepository $repository,
    ) {
    }

    public function findBySubdomain(string $subdomain): ?PublicationSite
    {
        $site = $this->repository->findBySubdomain($subdomain);

        return $site === null
            ? null
            : new PublicationSite($site->getSubdomain(), $site->getCoordinate());
    }

    /**
     * @return iterable<PublicationSite>
     */
    public function findAll(): iterable
    {
        foreach ($this->repository->findAll() as $site) {
            yield new PublicationSite($site->getSubdomain(), $site->getCoordinate());
        }
    }
}
