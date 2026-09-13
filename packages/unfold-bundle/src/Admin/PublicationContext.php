<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Admin;

use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSite;

final readonly class PublicationContext
{
    public string $ownerPubkey;
    public string $dtag;

    public function __construct(
        public string $coordinate,
        public PublicationSettings $settings,
        public PublicationMount $mount,
        public string $adminPathPrefix,
        public ?PublicationSite $site = null,
        public ?string $publicUrl = null,
    ) {
        [, $this->ownerPubkey, $this->dtag] = explode(':', $coordinate, 3);
    }
}
