<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Highlight;
use App\Repository\HighlightRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Projects incoming NIP-84 highlight events (kind 9802) into the dedicated
 * Highlight table so they are queryable by article coordinate.
 *
 * Previously the Highlight table was only populated by dedicated commands /
 * message handlers (FetchHighlightsCommand, RefreshArticleHighlightsHandler,
 * FetchHighlightsHandler, the publish endpoint). Highlight events arriving
 * through any other ingestion path (gateway, follow/author content fetches,
 * relay subscription workers) only landed in the generic `event` table, which
 * made them appear on `/highlights` (which reads from `event`) but invisible
 * on the article page (which reads from `highlight`).
 *
 * This projector closes that gap: GenericEventProjector calls it for every
 * kind 9802 event so the row is always written, idempotently keyed by event id.
 *
 * Accepted article references (`a` / `A` tags):
 *   - 30023 (longform)
 *   - 30024 (longform draft)
 *   - 30040 (publication index)
 *   - 30041 (publication content / AsciiDoc)
 */
class HighlightProjector
{
    private const ACCEPTED_ARTICLE_KIND_PREFIXES = ['30023:', '30024:', '30040:', '30041:'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HighlightRepository $highlightRepository,
        private readonly LoggerInterface $logger,
        private readonly HighlightService $highlightService,
    ) {}

    /**
     * Project a raw Nostr event (object or array) into a Highlight entity.
     *
     * Idempotent: returns the existing row if the event was already projected.
     * Returns null when the event is missing required fields or has empty content.
     */
    public function projectFromEvent(object|array $event): ?Highlight
    {
        $event = $this->normalize($event);
        if ($event === null) {
            return null;
        }

        $eventId = (string) ($event['id'] ?? '');
        if ($eventId === '') {
            return null;
        }

        // Idempotent: refresh cachedAt on the existing row, do not duplicate.
        $existing = $this->highlightRepository->findOneBy(['eventId' => $eventId]);
        if ($existing) {
            $existing->setCachedAt(new \DateTimeImmutable());
            try {
                $this->entityManager->flush();
            } catch (\Throwable $e) {
                $this->logger->debug('HighlightProjector: failed to refresh cachedAt', [
                    'event_id' => $eventId,
                    'error' => $e->getMessage(),
                ]);
            }
            return $existing;
        }

        $content = (string) ($event['content'] ?? '');
        if ($content === '') {
            // Highlights are quoted text; an empty content row is useless to readers.
            return null;
        }

        [$articleCoordinate, $context] = $this->extractRefs($event['tags'] ?? []);

        $highlight = new Highlight();
        $highlight->setEventId($eventId);
        $highlight->setArticleCoordinate($articleCoordinate);
        $highlight->setContent($content);
        $highlight->setPubkey((string) ($event['pubkey'] ?? ''));
        $highlight->setCreatedAt((int) ($event['created_at'] ?? time()));
        $highlight->setContext($context);
        $highlight->setRawEvent($event);

        try {
            $this->entityManager->persist($highlight);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            // Duplicate key (race with another worker) — fetch and return the winner.
            if (str_contains($e->getMessage(), '23505') || str_contains($e->getMessage(), 'duplicate key')) {
                return $this->highlightRepository->findOneBy(['eventId' => $eventId]);
            }
            $this->logger->warning('HighlightProjector: failed to persist highlight', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        // Invalidate the article's Redis highlight cache so the next page render
        // surfaces the new highlight immediately rather than after Redis TTL.
        if ($articleCoordinate !== null) {
            try {
                $this->highlightService->invalidateRedisCache($articleCoordinate);
            } catch (\Throwable) {
                // Best-effort only
            }
        }

        $this->logger->debug('HighlightProjector: projected highlight', [
            'event_id' => $eventId,
            'article_coordinate' => $articleCoordinate,
        ]);

        return $highlight;
    }

    /**
     * Extract the article coordinate (from `a`/`A` tags) and context (from
     * `context` tag) out of a tag array.
     *
     * @param mixed $tags
     * @return array{0: ?string, 1: ?string} [articleCoordinate, context]
     */
    public function extractRefs(mixed $tags): array
    {
        if (!is_array($tags)) {
            return [null, null];
        }

        $articleCoordinate = null;
        $context = null;

        foreach ($tags as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }
            $name = $tag[0] ?? '';
            $value = (string) ($tag[1] ?? '');

            if ($articleCoordinate === null && ($name === 'a' || $name === 'A')) {
                foreach (self::ACCEPTED_ARTICLE_KIND_PREFIXES as $prefix) {
                    if (str_starts_with($value, $prefix)) {
                        $articleCoordinate = $value;
                        break;
                    }
                }
            } elseif ($context === null && $name === 'context') {
                $context = $value;
            }
        }

        return [$articleCoordinate, $context];
    }

    /**
     * Normalize an event (object|array) into an associative array shape used
     * here. Returns null if it does not look like a kind 9802 event.
     */
    private function normalize(object|array $event): ?array
    {
        if (is_object($event)) {
            $event = [
                'id' => $event->id ?? null,
                'kind' => $event->kind ?? null,
                'pubkey' => $event->pubkey ?? null,
                'content' => $event->content ?? null,
                'created_at' => $event->created_at ?? null,
                'tags' => $event->tags ?? [],
                'sig' => $event->sig ?? null,
            ];
        }

        if ((int) ($event['kind'] ?? 0) !== 9802) {
            return null;
        }

        return $event;
    }
}

