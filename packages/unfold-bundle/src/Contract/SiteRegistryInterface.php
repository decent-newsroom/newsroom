<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

interface SiteRegistryInterface
{
    public function findBySubdomain(string $subdomain): ?PublicationSite;

    /** The oldest mapping for this coordinate, if any. */
    public function findByCoordinate(string $coordinate): ?PublicationSite;

    /**
     * @return iterable<PublicationSite>
     */
    public function findAll(): iterable;
}
