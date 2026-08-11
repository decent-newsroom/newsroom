<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Service;

use DecentNewsroom\ExpressionBundle\Contract\EventInterface;
use DecentNewsroom\ExpressionBundle\Contract\EventStoreInterface;
use DecentNewsroom\ExpressionBundle\Contract\RelayEventClientInterface;
use DecentNewsroom\ExpressionBundle\Contract\RelaySelectorInterface;
use DecentNewsroom\ExpressionBundle\Model\RuntimeContext;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Resolves generic Nostr events from an optional local store and relays.
 *
 * The local store is an optimization and is never required for bundle
 * operation. Relay results are merged with local results and deduplicated by
 * event id.
 */
final class EventResolver
{
    private const MAX_RELAYS = 16;

    public function __construct(
        private readonly RelayEventClientInterface $relayClient,
        private readonly RelaySelectorInterface $relaySelector,
        private readonly ?EventStoreInterface $eventStore = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function findById(string $id, ?RuntimeContext $context = null): ?EventInterface
    {
        $event = $this->eventStore?->findById($id);
        if ($event !== null) {
            return $event;
        }

        foreach ($this->fetch(['ids' => [$id], 'limit' => 1], $context) as $candidate) {
            if ($candidate->getId() === $id) {
                return $candidate;
            }
        }

        return null;
    }

    public function findByNaddr(
        int $kind,
        string $pubkey,
        string $identifier,
        ?RuntimeContext $context = null,
    ): ?EventInterface {
        $event = $this->eventStore?->findByNaddr($kind, $pubkey, $identifier);
        if ($event !== null) {
            return $event;
        }

        $events = $this->fetch([
            'kinds' => [$kind],
            'authors' => [$pubkey],
            '#d' => [$identifier],
            'limit' => 1,
        ], $context);

        return $events[0] ?? null;
    }

    public function findLatestByPubkeyAndKind(
        string $pubkey,
        int $kind,
        ?RuntimeContext $context = null,
    ): ?EventInterface {
        $event = $this->eventStore?->findLatestByPubkeyAndKind($pubkey, $kind);
        if ($event !== null) {
            return $event;
        }

        $events = $this->fetch([
            'kinds' => [$kind],
            'authors' => [$pubkey],
        ], $context);

        usort($events, static fn(EventInterface $a, EventInterface $b): int => $b->getCreatedAt() <=> $a->getCreatedAt());

        return $events[0] ?? null;
    }

    /**
     * @param string[] $ids
     * @return array<string, EventInterface>
     */
    public function findByIds(array $ids, ?RuntimeContext $context = null): array
    {
        $ids = array_values(array_unique($ids));
        $events = $this->eventStore?->findByIds($ids) ?? [];
        $missing = array_values(array_diff($ids, array_keys($events)));

        if ($missing !== []) {
            foreach ($this->fetch(['ids' => $missing], $context) as $event) {
                if ($event->getId() !== '') {
                    $events[$event->getId()] = $event;
                }
            }
        }

        return $events;
    }

    /**
     * @param string[] $coordinates
     * @return array<string, EventInterface>
     */
    public function findByCoordinates(array $coordinates, ?RuntimeContext $context = null): array
    {
        $coordinates = array_values(array_unique($coordinates));
        $events = $this->eventStore?->findByCoordinates($coordinates) ?? [];
        $missing = array_values(array_diff($coordinates, array_keys($events)));

        foreach ($this->groupCoordinates($missing) as $group) {
            foreach ($this->fetch([
                'kinds' => [$group['kind']],
                'authors' => [$group['pubkey']],
                '#d' => $group['identifiers'],
            ], $context) as $event) {
                $coordinate = $this->coordinateOf($event);
                if ($coordinate !== null && in_array($coordinate, $group['coordinates'], true)) {
                    $events[$coordinate] = $event;
                }
            }
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $filter
     * @return EventInterface[]
     */
    public function findByFilter(array $filter, ?RuntimeContext $context = null): array
    {
        $localFilter = $filter;
        unset($localFilter['relays']);
        $localEvents = $this->eventStore?->findByFilter($localFilter) ?? [];
        $relayEvents = $this->fetch($filter, $context);

        return $this->merge($localEvents, $relayEvents);
    }

    /**
     * @param string[] $kinds
     * @return EventInterface[]
     */
    public function findReferencingEvents(
        string $tagName,
        string $tagValue,
        array $kinds = [],
        int $limit = 200,
        ?RuntimeContext $context = null,
    ): array {
        $localEvents = $this->eventStore?->findReferencingEvents($tagName, $tagValue, $kinds, $limit) ?? [];
        $relayEvents = $this->fetch([
            'kinds' => $kinds,
            '#' . $tagName => [$tagValue],
            'limit' => $limit,
        ], $context);

        return array_slice($this->merge($localEvents, $relayEvents), 0, $limit);
    }

    /**
     * @param string[] $tagValues
     * @return EventInterface[]
     */
    public function findReferencingEventsBatch(
        string $tagName,
        array $tagValues,
        array $kinds = [],
        int $limit = 1000,
        ?RuntimeContext $context = null,
    ): array {
        $localEvents = $this->eventStore?->findReferencingEventsBatch($tagName, $tagValues, $kinds, $limit) ?? [];
        $relayEvents = $this->fetch([
            'kinds' => $kinds,
            '#' . $tagName => array_values(array_unique($tagValues)),
            'limit' => $limit,
        ], $context);

        return array_slice($this->merge($localEvents, $relayEvents), 0, $limit);
    }

    public function countReferencingEvents(
        string $eventId,
        ?string $coordinate,
        array $kinds,
        ?RuntimeContext $context = null,
    ): int {
        if ($this->eventStore !== null) {
            return $this->eventStore->countReferencingEvents($eventId, $coordinate, $kinds);
        }

        $tagValues = array_values(array_filter([$eventId, $coordinate]));
        if ($tagValues === []) {
            return 0;
        }

        $relayEvents = $this->fetch([
            'kinds' => $kinds,
            '#e' => [$eventId],
            'limit' => 1000,
        ], $context);
        if ($coordinate !== null) {
            $relayEvents = array_merge($relayEvents, $this->fetch([
                'kinds' => $kinds,
                '#a' => [$coordinate],
                'limit' => 1000,
            ], $context));
        }

        return count($this->merge([], $relayEvents));
    }

    /**
     * @param array<string, mixed> $filter
     * @return EventInterface[]
     */
    private function fetch(array $filter, ?RuntimeContext $context): array
    {
        $relayUrls = $this->buildRelayUrls($context, (array) ($filter['relays'] ?? []));
        unset($filter['relays']);

        if ($relayUrls === []) {
            return [];
        }

        try {
            return $this->relayClient->fetch(
                kinds: array_map('intval', (array) ($filter['kinds'] ?? [])),
                filter: $filter,
                relayUrls: $relayUrls,
                pubkey: ($context?->mePubkey ?? '') !== '' ? $context?->mePubkey : null,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Generic relay event lookup failed', [
                'filter' => $filter,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param string[] $explicitRelays
     * @return string[]
     */
    private function buildRelayUrls(?RuntimeContext $context, array $explicitRelays): array
    {
        $urls = $explicitRelays;
        if ($context !== null) {
            $urls = array_merge($urls, $context->relays);
        }
        $urls = array_merge($urls, $this->relaySelector->ensureLocalRelay([]));
        $urls = array_merge($urls, $this->relaySelector->getDefaultRelays());
        $urls = array_merge($urls, $this->relaySelector->getContentRelays());

        if (($context?->mePubkey ?? '') !== '') {
            $urls = array_merge($urls, $this->relaySelector->getAuthorRelays($context->mePubkey));
        }

        $seen = [];
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

    /**
     * @param EventInterface[] $first
     * @param EventInterface[] $second
     * @return EventInterface[]
     */
    private function merge(array $first, array $second): array
    {
        $merged = [];
        foreach ([$first, $second] as $events) {
            foreach ($events as $event) {
                if ($event->getId() !== '') {
                    $merged[$event->getId()] = $event;
                }
            }
        }

        return array_values($merged);
    }

    /**
     * @param string[] $coordinates
     * @return array<int, array{kind:int,pubkey:string,identifiers:string[],coordinates:string[]}>
     */
    private function groupCoordinates(array $coordinates): array
    {
        $groups = [];
        foreach ($coordinates as $coordinate) {
            $parts = explode(':', $coordinate, 3);
            if (count($parts) !== 3 || !ctype_digit($parts[0])) {
                continue;
            }

            $key = $parts[0] . ':' . $parts[1];
            $groups[$key]['kind'] = (int) $parts[0];
            $groups[$key]['pubkey'] = $parts[1];
            $groups[$key]['identifiers'][] = $parts[2];
            $groups[$key]['coordinates'][] = $coordinate;
        }

        return array_values($groups);
    }

    private function coordinateOf(EventInterface $event): ?string
    {
        foreach ($event->getTags() as $tag) {
            if (($tag[0] ?? '') === 'd' && isset($tag[1])) {
                return "{$event->getKind()}:{$event->getPubkey()}:{$tag[1]}";
            }
        }

        return null;
    }
}
