<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

final readonly class PublicationSite
{
    public function __construct(
        public string $subdomain,
        public string $coordinate,
    ) {
    }
}
