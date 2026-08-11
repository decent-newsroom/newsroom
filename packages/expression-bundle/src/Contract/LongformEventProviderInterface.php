<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Contract;

interface LongformEventProviderInterface
{
    public function findByEventId(string $eventId): ?EventInterface;

    /** @param string[] $slugs @return EventInterface[] */
    public function findByPubkeyAndSlugs(string $pubkey, array $slugs): array;
}
