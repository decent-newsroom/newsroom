<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

interface PublicationTreeLookupInterface
{
    /**
     * @return list<NostrEvent>
     */
    public function findChildren(string $coordinate): array;

    /**
     * @return list<NostrEvent>
     */
    public function findDescendants(string $coordinate, int $maxDepth): array;
}
