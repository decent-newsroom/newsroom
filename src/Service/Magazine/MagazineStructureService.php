<?php

declare(strict_types=1);

namespace App\Service\Magazine;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;

final readonly class MagazineStructureService
{
    public function __construct(
        private EventRepository $eventRepository,
    ) {
    }

    /**
     * Hydrate an Event from a raw DB row without going through a full ORM query.
     *
     * @param array<string, mixed> $row
     */
    public function hydrateEventFromRow(array $row): Event
    {
        $event = new Event();
        $event->setId((string) ($row['id'] ?? ''));
        if (isset($row['event_id'])) {
            $event->setEventId((string) $row['event_id']);
        }
        $event->setKind((int) ($row['kind'] ?? 0));
        $event->setPubkey((string) ($row['pubkey'] ?? ''));
        $event->setContent((string) ($row['content'] ?? ''));
        $event->setCreatedAt((int) ($row['created_at'] ?? 0));
        $event->setSig((string) ($row['sig'] ?? ''));

        $tags = $row['tags'] ?? [];
        if (is_string($tags)) {
            $tags = json_decode($tags, true) ?? [];
        }
        $event->setTags(is_array($tags) ? $tags : []);

        return $event;
    }

    public function findLatestIndexBySlug(string $slug): ?Event
    {
        $conn = $this->eventRepository->getEntityManager()->getConnection();

        $row = $conn->executeQuery(
            'SELECT * FROM event e WHERE e.kind = :kind AND e.d_tag = :slug ORDER BY e.created_at DESC LIMIT 1',
            [
                'kind' => KindsEnum::PUBLICATION_INDEX->value,
                'slug' => $slug,
            ],
        )->fetchAssociative();

        if ($row === false) {
            // Backward compatibility for rows that predate d_tag backfill.
            $row = $conn->executeQuery(
                "SELECT * FROM event e
                 WHERE e.kind = :kind
                   AND EXISTS (
                       SELECT 1 FROM jsonb_array_elements(e.tags) AS tag
                       WHERE tag->>0 = 'd' AND tag->>1 = :slug
                   )
                 ORDER BY e.created_at DESC
                 LIMIT 1",
                [
                    'kind' => KindsEnum::PUBLICATION_INDEX->value,
                    'slug' => $slug,
                ],
            )->fetchAssociative();
        }

        return $row !== false ? $this->hydrateEventFromRow($row) : null;
    }

    public function parseStructure(Event $magazine): MagazineStructure
    {
        $categoryTags = [];
        $chapterCoordinates = [];
        $frontPageArticleCoordinate = null;

        foreach ($magazine->getTags() as $tag) {
            if (!isset($tag[0], $tag[1]) || $tag[0] !== 'a') {
                continue;
            }

            $parts = explode(':', (string) $tag[1], 3);
            if (count($parts) !== 3) {
                continue;
            }

            $kind = (int) $parts[0];
            if ($kind === KindsEnum::PUBLICATION_INDEX->value) {
                $categoryTags[] = $tag;
                continue;
            }

            if ($kind === KindsEnum::PUBLICATION_CONTENT->value) {
                $chapterCoordinates[] = (string) $tag[1];
                continue;
            }

            if (($kind === KindsEnum::LONGFORM->value || $kind === KindsEnum::LONGFORM_DRAFT->value) && $frontPageArticleCoordinate === null) {
                $frontPageArticleCoordinate = (string) $tag[1];
            }
        }

        return new MagazineStructure(
            categoryTags: $categoryTags,
            chapterCoordinates: $chapterCoordinates,
            frontPageArticleCoordinate: $frontPageArticleCoordinate,
        );
    }

    /**
     * @param array<int, array<mixed>> $categoryTags
     * @return array<int, array{categorySlug: string, categoryTitle: string, articleCoordinate: ?string}>
     */
    public function buildCategoryPreviewPayload(array $categoryTags): array
    {
        if ($categoryTags === []) {
            return [];
        }

        $categoryCoordinates = [];
        foreach ($categoryTags as $tag) {
            if (!isset($tag[1]) || !is_string($tag[1])) {
                continue;
            }
            $categoryCoordinates[] = $tag[1];
        }

        if ($categoryCoordinates === []) {
            return [];
        }

        $categoryMap = $this->eventRepository->findByCoordinates($categoryCoordinates);
        $payload = [];

        foreach ($categoryCoordinates as $coordinate) {
            $parts = explode(':', $coordinate, 3);
            $categorySlug = $parts[2] ?? '';
            if ($categorySlug === '') {
                continue;
            }

            $categoryEvent = $categoryMap[$coordinate] ?? null;
            if (!$categoryEvent instanceof Event) {
                $payload[] = [
                    'categorySlug' => $categorySlug,
                    'categoryTitle' => $categorySlug,
                    'articleCoordinate' => null,
                ];
                continue;
            }

            $payload[] = [
                'categorySlug' => $categorySlug,
                'categoryTitle' => $categoryEvent->getTitle() ?? $categorySlug,
                'articleCoordinate' => $this->findFirstCategoryArticleCoordinate($categoryEvent),
            ];
        }

        return $payload;
    }

    public function findFirstCategoryArticleCoordinate(Event $categoryEvent): ?string
    {
        foreach ($categoryEvent->getTags() as $tag) {
            if (!isset($tag[0], $tag[1]) || $tag[0] !== 'a' || !is_string($tag[1])) {
                continue;
            }

            $parts = explode(':', $tag[1], 3);
            if (count($parts) !== 3) {
                continue;
            }

            $kind = (int) $parts[0];
            if ($kind === KindsEnum::LONGFORM->value || $kind === KindsEnum::LONGFORM_DRAFT->value) {
                return $tag[1];
            }
        }

        return null;
    }

    /**
     * @param string[] $chapterCoordinates
     * @return array<int, array{event: ?Event, coordinate: string, fetched: bool, slug?: string, pubkey?: string, kind?: int}>
     */
    public function resolveChapters(array $chapterCoordinates): array
    {
        if ($chapterCoordinates === []) {
            return [];
        }

        $chapterMap = $this->eventRepository->findByCoordinates($chapterCoordinates);
        $chapters = [];
        foreach ($chapterCoordinates as $coordinate) {
            $parts = explode(':', $coordinate, 3);
            if (count($parts) !== 3) {
                continue;
            }

            $kind = (int) $parts[0];
            $pubkey = $parts[1];
            $slug = $parts[2];

            $chapter = $chapterMap[$coordinate] ?? null;
            if ($chapter instanceof Event) {
                $chapters[] = [
                    'event' => $chapter,
                    'coordinate' => $coordinate,
                    'fetched' => true,
                ];
                continue;
            }

            $chapters[] = [
                'event' => null,
                'coordinate' => $coordinate,
                'slug' => $slug,
                'pubkey' => $pubkey,
                'kind' => $kind,
                'fetched' => false,
            ];
        }

        return $chapters;
    }
}