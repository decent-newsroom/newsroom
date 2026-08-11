<?php

declare(strict_types=1);

namespace App\Expression;

use DecentNewsroom\ExpressionBundle\Contract\EventInterface;
use DecentNewsroom\ExpressionBundle\Contract\EventStoreInterface;
use App\Repository\EventRepository;

final class ExpressionEventStore implements EventStoreInterface
{
    public function __construct(
        private readonly EventRepository $repository,
    ) {}

    public function findById(string $id): ?EventInterface
    {
        return $this->repository->find($id);
    }

    public function findByNaddr(int $kind, string $pubkey, string $identifier): ?EventInterface
    {
        return $this->repository->findByNaddr($kind, $pubkey, $identifier);
    }

    public function findLatestByPubkeyAndKind(string $pubkey, int $kind): ?EventInterface
    {
        return $this->repository->findLatestByPubkeyAndKind($pubkey, $kind);
    }

    public function findByIds(array $ids): array
    {
        return $this->repository->findByIds($ids);
    }

    public function findByCoordinates(array $coordinates): array
    {
        return $this->repository->findByCoordinates($coordinates);
    }

    public function findByFilter(array $filter): array
    {
        return $this->repository->findByFilter($filter);
    }

    public function findReferencingEvents(
        string $tagName,
        string $tagValue,
        array $kinds = [],
        int $limit = 200,
    ): array {
        return $this->repository->findReferencingEvents($tagName, $tagValue, $kinds, $limit);
    }

    public function findReferencingEventsBatch(
        string $tagName,
        array $tagValues,
        array $kinds = [],
        int $limit = 1000,
    ): array {
        return $this->repository->findReferencingEventsBatch($tagName, $tagValues, $kinds, $limit);
    }

    public function countReferencingEvents(string $eventId, ?string $coordinate, array $kinds): int
    {
        return $this->repository->countReferencingEvents($eventId, $coordinate, $kinds);
    }
}
