<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Source;

use App\Entity\Event;
use App\ExpressionBundle\Exception\UnresolvedRefException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\Repository\EventRepository;
use App\Service\Nostr\NostrRequestExecutor;
use App\Service\Nostr\RelayRegistry;
use App\Service\Nostr\RelaySetFactory;
use Psr\Log\LoggerInterface;

/**
 * Resolves event ID references: DB-first, relay fallback.
 *
 * For the relay fallback, the resolver queries a broad union of relays:
 * the local relay, the configured content relays, and — as an additional
 * probe — the requesting user's NIP-65 read relays. The viewer is NOT
 * assumed to be the author of the input event: expressions and the
 * spells they reference can be authored by anyone and evaluated by
 * anyone, so we cannot rely on the viewer's relays to carry an arbitrary
 * referenced event. The viewer's relays are included at a lower priority
 * purely as an extra probe; the canonical fetch paths are the local
 * relay and the configured content relays.
 */
final class EventIdSourceResolver
{
    /** Hard cap on the number of relays queried per resolution. */
    private const MAX_RELAYS = 16;

    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly NostrRequestExecutor $requestExecutor,
        private readonly RelayRegistry $relayRegistry,
        private readonly RelaySetFactory $relaySetFactory,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return NormalizedItem[] */
    public function resolve(string $eventId, RuntimeContext $ctx): array
    {
        // DB-first
        $event = $this->eventRepository->findById($eventId);
        if ($event !== null) {
            $this->logger->debug('Event resolved from DB', ['eventId' => $eventId]);
            return [new NormalizedItem($event)];
        }

        // Relay fallback — broad union. The event's author is unknown from an
        // event id alone; we cannot assume the viewer authored it.
        $relayUrls = $this->buildRelayUrlsFor($ctx);
        $relaySet  = $this->relaySetFactory->fromUrls($relayUrls);

        $this->logger->debug('Event not in DB, fetching from relays', [
            'eventId'    => $eventId,
            'relayCount' => count($relayUrls),
            'viewer'     => $ctx->mePubkey !== '' ? substr($ctx->mePubkey, 0, 12) . '…' : 'anonymous',
        ]);

        try {
            $rawEvents = $this->requestExecutor->fetch(
                kinds: [],
                filters: ['ids' => [$eventId], 'limit' => 1],
                relaySet: $relaySet,
                // Passed for NIP-42 AUTH against the viewer's session, not as an
                // authorship claim. The referenced event may have been authored
                // by anyone.
                pubkey: $ctx->mePubkey !== '' ? $ctx->mePubkey : null,
            );

            foreach ($rawEvents as $raw) {
                $event = $this->convertToEntity($raw);
                if ($event !== null) {
                    $this->logger->debug('Event resolved from relays', ['eventId' => $eventId]);
                    return [new NormalizedItem($event)];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Event relay fetch failed', [
                'eventId' => $eventId,
                'error'   => $e->getMessage(),
            ]);
        }

        throw new UnresolvedRefException("Event not found: {$eventId}");
    }

    /**
     * Assemble a deduplicated relay URL list for resolving an event ID.
     *
     * Order of preference (earliest = highest priority):
     *   1. the local relay (warm cache of project-specific content; near-zero latency)
     *   2. the application's content relays (broad public coverage — where events
     *      authored by arbitrary pubkeys are most likely to live)
     *   3. the viewer's NIP-65 read relays, if known (extra probe; no authorship
     *      assumption — the viewer may not be related to the event's author at all)
     *
     * Capped at {@see self::MAX_RELAYS} to keep the fan-out bounded.
     *
     * @return string[]
     */
    private function buildRelayUrlsFor(RuntimeContext $ctx): array
    {
        // 1. Local relay first (warm strfry cache).
        $urls = $this->relayRegistry->ensureLocalRelayInList([]);

        // 2. Content relays — the canonical public-content paths.
        foreach ($this->relayRegistry->getContentRelays() as $url) {
            $urls[] = $url;
        }

        // 3. Viewer's NIP-65 read relays as a low-priority probe.
        if ($ctx->mePubkey !== '') {
            foreach ($this->relaySetFactory->getAuthorRelayUrls($ctx->mePubkey) as $url) {
                $urls[] = $url;
            }
        }

        // Deduplicate, preserving the first occurrence of each URL.
        $seen   = [];
        $result = [];
        foreach ($urls as $url) {
            $canonical = $this->relayRegistry->resolveToLocalUrl($url);
            if (isset($seen[$canonical])) {
                continue;
            }
            $seen[$canonical] = true;
            $result[] = $url;
            if (count($result) >= self::MAX_RELAYS) {
                break;
            }
        }

        return $result;
    }

    private function convertToEntity(object $raw): ?Event
    {
        if (!isset($raw->id, $raw->pubkey, $raw->kind)) {
            return null;
        }
        $event = new Event();
        $event->setId($raw->id);
        $event->setPubkey($raw->pubkey);
        $event->setKind((int) $raw->kind);
        $event->setContent($raw->content ?? '');
        $event->setCreatedAt((int) ($raw->created_at ?? 0));
        $event->setTags((array) ($raw->tags ?? []));
        $event->setSig($raw->sig ?? '');
        return $event;
    }
}
