<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Contract;

interface EventStoreInterface
{
    public function findById(string $id): ?EventInterface;

    public function findByNaddr(int $kind, string $pubkey, string $identifier): ?EventInterface;

    public function findLatestByPubkeyAndKind(string $pubkey, int $kind): ?EventInterface;

    /** @return array<string, EventInterface> */
    public function findByIds(array $ids): array;

    /** @return array<string, EventInterface> */
    public function findByCoordinates(array $coordinates): array;

    /** @return EventInterface[] */
    public function findByFilter(array $filter): array;

    /** @return EventInterface[] */
    public function findReferencingEvents(
        string $tagName,
        string $tagValue,
        array $kinds = [],
        int $limit = 200,
    ): array;

    /** @return EventInterface[] */
    public function findReferencingEventsBatch(
        string $tagName,
        array $tagValues,
        array $kinds = [],
        int $limit = 1000,
    ): array;

    public function countReferencingEvents(string $eventId, ?string $coordinate, array $kinds): int;
}
