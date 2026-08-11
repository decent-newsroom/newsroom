<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Source;

use DecentNewsroom\ExpressionBundle\Contract\EventInterface;
use DecentNewsroom\ExpressionBundle\Contract\EventStoreInterface;
use DecentNewsroom\ExpressionBundle\Contract\RelayEventClientInterface;
use DecentNewsroom\ExpressionBundle\Contract\RelaySelectorInterface;
use DecentNewsroom\ExpressionBundle\Exception\UnresolvedRefException;
use DecentNewsroom\ExpressionBundle\Model\NormalizedItem;
use DecentNewsroom\ExpressionBundle\Model\RuntimeContext;
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
        private readonly EventStoreInterface $eventStore,
        private readonly RelayEventClientInterface $relayClient,
        private readonly RelaySelectorInterface $relaySelector,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return NormalizedItem[] */
    public function resolve(string $eventId, RuntimeContext $ctx): array
    {
        // DB-first
        $event = $this->eventStore->findById($eventId);
        if ($event !== null) {
            $this->logger->debug('Event resolved from DB', ['eventId' => $eventId]);
            return [new NormalizedItem($event)];
        }

        // Relay fallback — broad union. The event's author is unknown from an
        // event id alone; we cannot assume the viewer authored it.
        $relayUrls = $this->buildRelayUrlsFor($ctx);
        $this->logger->debug('Event not in DB, fetching from relays', [
            'eventId'    => $eventId,
            'relayCount' => count($relayUrls),
            'viewer'     => $ctx->mePubkey !== '' ? substr($ctx->mePubkey, 0, 12) . '…' : 'anonymous',
        ]);

        try {
            $rawEvents = $this->relayClient->fetch(
                kinds: [],
                filter: ['ids' => [$eventId], 'limit' => 1],
                relayUrls: $relayUrls,
                pubkey: $ctx->mePubkey !== '' ? $ctx->mePubkey : null,
            );

            foreach ($rawEvents as $raw) {
                $this->logger->debug('Event resolved from relays', ['eventId' => $eventId]);
                return [new NormalizedItem($raw)];
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
        $urls = $this->relaySelector->ensureLocalRelay([]);

        // 2. Content relays — the canonical public-content paths.
        foreach ($this->relaySelector->getContentRelays() as $url) {
            $urls[] = $url;
        }

        // 3. Viewer's NIP-65 read relays as a low-priority probe.
        if ($ctx->mePubkey !== '') {
            foreach ($this->relaySelector->getAuthorRelays($ctx->mePubkey) as $url) {
                $urls[] = $url;
            }
        }

        // Deduplicate, preserving the first occurrence of each URL.
        $seen   = [];
        $result = [];
        foreach ($urls as $url) {
            $canonical = $this->relaySelector->canonicalize($url);
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

}
