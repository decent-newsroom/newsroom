<?php

declare(strict_types=1);

namespace App\Bookshelf;

use App\Repository\EventRepository;
use DecentNewsroom\BookshelfBundle\Contract\DirectoryEventStoreInterface;

final class BookshelfEventStore implements DirectoryEventStoreInterface
{
    public function __construct(
        private readonly EventRepository $eventRepository,
    ) {
    }

    public function findAllByPubkeyAndKind(string $pubkeyHex, int $kind, int $limit = 100): array
    {
        return $this->eventRepository->findAllByPubkeyAndKind($pubkeyHex, $kind, $limit);
    }
}
