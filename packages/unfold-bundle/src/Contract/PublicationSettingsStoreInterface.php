<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;

/** Hosts persist local settings independently of domain claims and Nostr events. */
interface PublicationSettingsStoreInterface
{
    public function find(string $coordinate): ?PublicationSettings;

    public function save(PublicationSettings $settings): void;
}
