<?php

declare(strict_types=1);

namespace App\Unfold;

use App\Service\Cache\RedisCacheService;
use DecentNewsroom\UnfoldBundle\Contract\ProfileMetadata;
use DecentNewsroom\UnfoldBundle\Contract\ProfileMetadataProviderInterface;

final readonly class ProfileMetadataAdapter implements ProfileMetadataProviderInterface
{
    public function __construct(private RedisCacheService $cache)
    {
    }

    public function getMetadata(string $pubkey): ProfileMetadata
    {
        return $this->map($this->cache->getMetadata($pubkey));
    }

    public function getMultipleMetadata(array $pubkeys): array
    {
        $result = [];
        foreach ($this->cache->getMultipleMetadata($pubkeys) as $pubkey => $metadata) {
            $result[(string) $pubkey] = $this->map($metadata);
        }
        return $result;
    }

    private function map(object $metadata): ProfileMetadata
    {
        return new ProfileMetadata(
            name: $metadata->name ?? null,
            displayName: $metadata->displayName ?? null,
            picture: $metadata->picture ?? null,
            lud16: $this->list($metadata->lud16 ?? []),
            lud06: $this->list($metadata->lud06 ?? []),
        );
    }

    /** @return list<string> */
    private function list(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
