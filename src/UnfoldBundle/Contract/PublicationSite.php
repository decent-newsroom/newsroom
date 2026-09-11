<?php

declare(strict_types=1);

namespace App\UnfoldBundle\Contract;

final readonly class PublicationSite
{
    public function __construct(
        public string $subdomain,
        public string $coordinate,
    ) {
    }
}
