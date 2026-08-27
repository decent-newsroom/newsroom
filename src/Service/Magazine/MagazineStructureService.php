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
        if (array_key_exists('d_tag', $row) && $row['d_tag'] !== null) {
            $event->setDTag((string) $row['d_tag']);
        } else {
            $event->extractAndSetDTag();
        }

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
        $chapterRelayHints = [];
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
                $coordinate = (string) $tag[1];
                $chapterCoordinates[] = $coordinate;
                $relayHint = $this->normalizeRelayHint($tag[2] ?? null);
                if ($relayHint !== null) {
                    $chapterRelayHints[$coordinate] ??= [];
                    if (!in_array($relayHint, $chapterRelayHints[$coordinate], true)) {
                        $chapterRelayHints[$coordinate][] = $relayHint;
                    }
                }
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
            chapterRelayHints: $chapterRelayHints,
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
     * @param array<string, string[]> $relayHintsByCoordinate
     * @return array<int, array{event: ?Event, coordinate: string, fetched: bool, relayHints: string[], slug?: string, pubkey?: string, kind?: int}>
     */
    public function resolveChapters(array $chapterCoordinates, array $relayHintsByCoordinate = []): array
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
            $relayHints = $this->normalizeRelayHints($relayHintsByCoordinate[$coordinate] ?? []);
            if ($chapter instanceof Event) {
                $chapters[] = [
                    'event' => $chapter,
                    'coordinate' => $coordinate,
                    'fetched' => true,
                    'relayHints' => $relayHints,
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
                'relayHints' => $relayHints,
            ];
        }

        return $chapters;
    }

    /**
     * Return missing kind-30041 chapter coordinates for a publication index.
     *
     * @return array<int, array{coordinate: string, kind: int, pubkey: string, identifier: string, relayHints: string[]}>
     */
    public function missingChapterFetchRequests(Event $magazine): array
    {
        $structure = $this->parseStructure($magazine);
        $chapters = $this->resolveChapters($structure->chapterCoordinates, $structure->chapterRelayHints);
        $requests = [];

        foreach ($chapters as $chapter) {
            if (($chapter['fetched'] ?? false) === true || ($chapter['kind'] ?? null) !== KindsEnum::PUBLICATION_CONTENT->value) {
                continue;
            }

            $requests[] = [
                'coordinate' => $chapter['coordinate'],
                'kind' => $chapter['kind'],
                'pubkey' => $chapter['pubkey'],
                'identifier' => $chapter['slug'],
                'relayHints' => $chapter['relayHints'],
            ];
        }

        return $requests;
    }

    /**
     * @param mixed[] $relayHints
     * @return string[]
     */
    private function normalizeRelayHints(array $relayHints): array
    {
        $normalized = [];
        foreach ($relayHints as $relayHint) {
            $relay = $this->normalizeRelayHint($relayHint);
            if ($relay !== null && !in_array($relay, $normalized, true)) {
                $normalized[] = $relay;
            }
        }

        return $normalized;
    }

    private function normalizeRelayHint(mixed $relayHint): ?string
    {
        if (!is_string($relayHint)) {
            return null;
        }

        $relayHint = rtrim(trim($relayHint), '/');
        if ($relayHint === '' || !preg_match('#^wss?://#i', $relayHint)) {
            return null;
        }

        return $relayHint;
    }
}