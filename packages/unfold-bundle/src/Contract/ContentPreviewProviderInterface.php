<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

interface ContentPreviewProviderInterface
{
    /**
     * Resolve locally available, unrestricted content without fetching from relays.
     * Missing or invalid coordinates are omitted; keys preserve the original input.
     *
     * @param list<string> $coordinates
     * @return array<string, array{title: ?string, author: ?string}>
     */
    public function findByCoordinates(array $coordinates): array;
}
