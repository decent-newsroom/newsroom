<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

interface SiteRegistryInterface
{
    public function findBySubdomain(string $subdomain): ?PublicationSite;

    /**
     * @return iterable<PublicationSite>
     */
    public function findAll(): iterable;
}
