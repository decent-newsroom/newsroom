<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

interface ProfileMetadataProviderInterface
{
    public function getMetadata(string $pubkey): ProfileMetadata;

    /**
     * @param list<string> $pubkeys
     * @return array<string, ProfileMetadata>
     */
    public function getMultipleMetadata(array $pubkeys): array;
}
