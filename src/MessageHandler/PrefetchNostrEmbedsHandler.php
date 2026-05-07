<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\KindsEnum;
use App\Message\PrefetchNostrEmbedsMessage;
use App\Repository\EventRepository;
use App\Service\ArticleEventProjector;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Bulk-fetches Nostr events that are referenced as embedded links inside an
 * article but were not in the local database when the article was rendered.
 *
 * The handler:
 *   1. Deduplicates the requested IDs / coordinates against what is already
 *      in the DB (so we never hit the network for things we already have).
 *   2. Fetches missing events by ID in one batched relay call.
 *   3. Fetches missing events by coordinate in one batched relay call.
 *   4. Projects every fetched event via GenericEventProjector (and additionally
 *      via ArticleEventProjector for longform kinds so article cards render
 *      correctly on the next page view).
 *
 * This is intentionally fire-and-forget: no Mercure update is sent back.
 * The already-present `nostr--deferred-embed` Stimulus controller that runs
 * client-side will find the events in the DB on its /api/preview/ call and
 * render them correctly.
 */
#[AsMessageHandler]
final class PrefetchNostrEmbedsHandler
{
    private const ARTICLE_KINDS = [
        KindsEnum::LONGFORM->value,
        KindsEnum::LONGFORM_DRAFT->value,
        30040, // publication index
        30041, // publication chapter
    ];

    public function __construct(
        private readonly NostrClient           $nostrClient,
        private readonly EventRepository       $eventRepository,
        private readonly GenericEventProjector $genericProjector,
        private readonly ArticleEventProjector $articleProjector,
        private readonly LoggerInterface       $logger,
    ) {}

    public function __invoke(PrefetchNostrEmbedsMessage $message): void
    {
        if ($message->isEmpty()) {
            return;
        }

        $coordinate = $message->getArticleCoordinate();
        $this->logger->info('Prefetching nostr embeds for article', [
            'coordinate'  => $coordinate,
            'event_ids'   => count($message->getEventIds()),
            'coordinates' => count($message->getCoordinates()),
        ]);

        // ── 1. Filter to genuinely missing IDs ─────────────────────────────
        $missingIds = $this->filterMissingIds($message->getEventIds());

        // ── 2. Filter to genuinely missing coordinates ──────────────────────
        $missingCoords = $this->filterMissingCoordinates($message->getCoordinates());

        if (empty($missingIds) && empty($missingCoords)) {
            $this->logger->debug('All embedded references already in DB', ['coordinate' => $coordinate]);
            return;
        }

        $relayHints = $message->getRelayHints();
        $fetched = [];

        // ── 3. Batch fetch by event ID ──────────────────────────────────────
        if (!empty($missingIds)) {
            try {
                $byId = $this->nostrClient->getEventsByIds(array_values($missingIds), $relayHints);
                $fetched = array_merge($fetched, array_values($byId));
                $this->logger->info('Fetched embed events by ID', [
                    'requested' => count($missingIds),
                    'received'  => count($byId),
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('Embed prefetch by event ID failed', [
                    'coordinate' => $coordinate,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        // ── 4. Batch fetch by coordinate ────────────────────────────────────
        if (!empty($missingCoords)) {
            try {
                $byCoord = $this->nostrClient->getEventsByCoordinates(
                    array_values($missingCoords),
                    $relayHints
                );
                $fetched = array_merge($fetched, array_values($byCoord));
                $this->logger->info('Fetched embed events by coordinate', [
                    'requested' => count($missingCoords),
                    'received'  => count($byCoord),
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('Embed prefetch by coordinate failed', [
                    'coordinate' => $coordinate,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        if (empty($fetched)) {
            $this->logger->debug('No embed events fetched from relays', ['coordinate' => $coordinate]);
            return;
        }

        // ── 5. Project each fetched event ───────────────────────────────────
        $projected = 0;
        foreach ($fetched as $rawEvent) {
            if (!is_object($rawEvent) || empty($rawEvent->id)) {
                continue;
            }

            try {
                $this->genericProjector->projectEventFromNostrEvent($rawEvent, 'embed-prefetch');
                $projected++;

                // Also project as article if it's a longform kind so Article entities
                // are available and cards render correctly.
                if (in_array((int) ($rawEvent->kind ?? 0), self::ARTICLE_KINDS, true)) {
                    try {
                        $this->articleProjector->projectArticleFromEvent($rawEvent, 'embed-prefetch');
                    } catch (\Throwable $e) {
                        $this->logger->debug('Article projection skipped for embed', [
                            'event_id' => $rawEvent->id,
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->debug('Event projection skipped for embed', [
                    'event_id' => $rawEvent->id ?? 'unknown',
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('Embed prefetch complete', [
            'coordinate' => $coordinate,
            'fetched'    => count($fetched),
            'projected'  => $projected,
        ]);
    }

    /**
     * Return only the IDs that are not yet in the event table.
     *
     * @param  string[] $ids
     * @return string[]
     */
    private function filterMissingIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $existing = $this->eventRepository->findExistingIds($ids);

        return array_values(array_filter($ids, static fn(string $id): bool => !isset($existing[$id])));
    }

    /**
     * Return only the coordinates that cannot be resolved from the event table.
     * Uses d_tag column lookup (no JSONB scan needed).
     *
     * @param  string[] $coordinates "kind:pubkey:d-tag"
     * @return string[]
     */
    private function filterMissingCoordinates(array $coordinates): array
    {
        if (empty($coordinates)) {
            return [];
        }

        $missing = [];
        foreach ($coordinates as $coord) {
            $parts = explode(':', $coord, 3);
            if (count($parts) !== 3) {
                continue;
            }

            [$kind, $pubkey, $d] = $parts;
            try {
                $found = $this->eventRepository->findByNaddr((int) $kind, $pubkey, $d);
                if (!$found) {
                    $missing[] = $coord;
                }
            } catch (\Throwable) {
                $missing[] = $coord; // assume missing on DB error
            }
        }

        return $missing;
    }
}

