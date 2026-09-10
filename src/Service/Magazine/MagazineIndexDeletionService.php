<?php

declare(strict_types=1);

namespace App\Service\Magazine;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use Doctrine\DBAL\Connection;

final readonly class MagazineIndexDeletionService
{
    public function __construct(
        private EventRepository $eventRepository,
        private Connection $connection,
    ) {
    }

    public function deleteRecursively(string $rootCoordinate): MagazineIndexDeletionResult
    {
        $root = $this->parsePublicationIndexCoordinate($rootCoordinate);
        if ($root === null) {
            throw new \InvalidArgumentException('Invalid publication index coordinate.');
        }

        $coordinates = $this->collectDescendantCoordinates($rootCoordinate);
        if ($coordinates === []) {
            return new MagazineIndexDeletionResult(0, 0);
        }

        return $this->connection->transactional(function () use ($coordinates, $root): MagazineIndexDeletionResult {
            $deletedIndexes = $this->deleteIndexEvents($coordinates);
            $deletedMagazineProjections = $this->connection->executeStatement(
                'DELETE FROM magazine WHERE slug = :slug AND pubkey = :pubkey',
                [
                    'slug' => $root['identifier'],
                    'pubkey' => $root['pubkey'],
                ],
            );

            return new MagazineIndexDeletionResult($deletedIndexes, $deletedMagazineProjections);
        });
    }

    /**
     * @return string[]
     */
    private function collectDescendantCoordinates(string $rootCoordinate): array
    {
        $coordinates = [];
        $pending = [$rootCoordinate];
        $seen = [];

        while ($pending !== []) {
            $batch = [];
            foreach ($pending as $coordinate) {
                if (isset($seen[$coordinate])) {
                    continue;
                }

                $seen[$coordinate] = true;
                $batch[] = $coordinate;
            }
            $pending = [];

            if ($batch === []) {
                continue;
            }

            $events = $this->eventRepository->findByCoordinates($batch);
            foreach ($batch as $coordinate) {
                $event = $events[$coordinate] ?? null;
                if (!$event instanceof Event) {
                    continue;
                }

                $coordinates[] = $coordinate;
                foreach ($event->getTags() as $tag) {
                    if (!isset($tag[0], $tag[1]) || $tag[0] !== 'a' || !is_string($tag[1])) {
                        continue;
                    }

                    if ($this->parsePublicationIndexCoordinate($tag[1]) !== null && !isset($seen[$tag[1]])) {
                        $pending[] = $tag[1];
                    }
                }
            }
        }

        return $coordinates;
    }

    /**
     * @param string[] $coordinates
     */
    private function deleteIndexEvents(array $coordinates): int
    {
        $deleted = 0;

        foreach (array_chunk($coordinates, 500) as $chunk) {
            $conditions = [];
            $parameters = [];

            foreach ($chunk as $index => $coordinate) {
                $parts = $this->parsePublicationIndexCoordinate($coordinate);
                if ($parts === null) {
                    continue;
                }

                $conditions[] = sprintf(
                    '(e.kind = :kind_%1$d AND e.pubkey = :pubkey_%1$d AND (e.d_tag = :identifier_%1$d OR (e.d_tag IS NULL AND e.tags @> CAST(:tag_%1$d AS jsonb))))',
                    $index,
                );
                $parameters["kind_{$index}"] = KindsEnum::PUBLICATION_INDEX->value;
                $parameters["pubkey_{$index}"] = $parts['pubkey'];
                $parameters["identifier_{$index}"] = $parts['identifier'];
                $parameters["tag_{$index}"] = json_encode([['d', $parts['identifier']]], JSON_THROW_ON_ERROR);
            }

            if ($conditions === []) {
                continue;
            }

            $deleted += $this->connection->executeStatement(
                'DELETE FROM event e WHERE ' . implode(' OR ', $conditions),
                $parameters,
            );
        }

        return $deleted;
    }

    /**
     * @return array{pubkey: string, identifier: string}|null
     */
    private function parsePublicationIndexCoordinate(string $coordinate): ?array
    {
        $parts = explode(':', $coordinate, 3);
        if (
            count($parts) !== 3
            || (int) $parts[0] !== KindsEnum::PUBLICATION_INDEX->value
            || $parts[1] === ''
            || $parts[2] === ''
        ) {
            return null;
        }

        return [
            'pubkey' => $parts[1],
            'identifier' => $parts[2],
        ];
    }
}
