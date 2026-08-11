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
use DecentNewsroom\ExpressionBundle\Parser\SpellParser;
use Psr\Log\LoggerInterface;

/**
 * Executes kind:777 spells: parse → build filter → query DB/relays → NormalizedItem[].
 */
final class SpellSourceResolver
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly SpellParser $spellParser,
        private readonly RelayEventClientInterface $relayClient,
        private readonly RelaySelectorInterface $relaySelector,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return NormalizedItem[] */
    public function resolve(string $spellAddress, RuntimeContext $ctx): array
    {
        $this->logger->debug('Resolving spell by address', ['address' => $spellAddress]);

        $spellEvent = $this->findEvent($spellAddress);

        return $this->executeSpell($spellEvent, $spellAddress, $ctx);
    }

    /**
     * Execute a spell from an already-resolved Event (skips DB lookup).
     *
     * @return NormalizedItem[]
     */
    public function executeEvent(EventInterface $spellEvent, RuntimeContext $ctx): array
    {
        $label = $spellEvent->getId() ?: 'unknown';
        $this->logger->debug('Executing spell from pre-resolved event', ['eventId' => $label]);

        return $this->executeSpell($spellEvent, $label, $ctx);
    }

    /** @return NormalizedItem[] */
    private function executeSpell(EventInterface $spellEvent, string $label, RuntimeContext $ctx): array
    {
        $start = microtime(true);
        $filter = $this->spellParser->parse($spellEvent, $ctx);

        // Fanout strategy: the local DB and default strfry relay only contain a
        // narrow, pre-indexed subset of events. For spell evaluation we want the
        // freshest and broadest possible results, so we ALWAYS query the user's
        // declared NIP-65 read relays (from RuntimeContext), unioned with any
        // explicit `relays` tags on the spell itself. DB results are merged as
        // a supplement / fallback.
        $spellRelays = $filter['relays'] ?? [];
        $userRelays = $ctx->relays;
        $queryRelays = array_values(array_unique(array_merge($spellRelays, $userRelays)));
        if ($queryRelays === []) {
            $queryRelays = $this->relaySelector->getDefaultRelays();
        }

        $this->logger->debug('Spell filter built', [
            'label' => $label,
            'kinds' => $filter['kinds'] ?? [],
            'limit' => $filter['limit'] ?? null,
            'spellRelays' => $spellRelays,
            'userRelays' => $userRelays,
        ]);

        // 1) Query relays (user + spell). If none available, fall back to the
        //    project default content relays inside fetchFromRelays().
        $relayFilter = $filter;
        if (!empty($queryRelays)) {
            $relayFilter['relays'] = $queryRelays;
        } else {
            unset($relayFilter['relays']);
        }
        $relayEvents = $this->fetchFromRelays($relayFilter);

        // 2) Supplement with local DB (covers the narrow pre-indexed subset
        //    and acts as a safety net if all relays fail).
        $dbEvents = $this->eventStore->findByFilter($filter);

        // Merge + dedupe by event id, preserving relay results first.
        $merged = [];
        foreach ([$relayEvents, $dbEvents] as $bucket) {
            foreach ($bucket as $event) {
                $id = $event->getId();
                if ($id === '') {
                    continue;
                }
                if (!isset($merged[$id])) {
                    $merged[$id] = $event;
                }
            }
        }
        $events = array_values($merged);

        // Optional: honor the spell's limit on the merged result set.
        if (!empty($filter['limit']) && count($events) > (int) $filter['limit']) {
            // Keep newest first by created_at.
            usort($events, static fn(EventInterface $a, EventInterface $b) => $b->getCreatedAt() <=> $a->getCreatedAt());
            $events = array_slice($events, 0, (int) $filter['limit']);
        }

        $source = sprintf('relays(%d)+db(%d)', count($relayEvents), count($dbEvents));

        $ms = round((microtime(true) - $start) * 1000);
        $this->logger->info('Spell resolved', [
            'label' => $label,
            'source' => $source,
            'events' => count($events),
            'queryRelays' => $queryRelays,
            'ms' => $ms,
        ]);

        return array_map(fn(EventInterface $e) => new NormalizedItem($e), $events);
    }

    private function findEvent(string $address): EventInterface
    {
        [$kind, $pubkey, $d] = explode(':', $address, 3);
        $event = $this->eventStore->findByNaddr((int) $kind, $pubkey, $d);
        if ($event === null) {
            throw new UnresolvedRefException("Spell not found: {$address}");
        }
        return $event;
    }

    /**
     * @param array<string, mixed> $filter
     * @return Event[]
     */
    private function fetchFromRelays(array $filter): array
    {
        $kinds = $filter['kinds'] ?? [];
        $relayFilters = [];
        if (isset($filter['authors'])) {
            $relayFilters['authors'] = $filter['authors'];
        }
        if (isset($filter['since'])) {
            $relayFilters['since'] = $filter['since'];
        }
        if (isset($filter['until'])) {
            $relayFilters['until'] = $filter['until'];
        }
        if (isset($filter['limit'])) {
            $relayFilters['limit'] = $filter['limit'];
        }

        // Tag filters
        foreach ($filter as $key => $values) {
            if (str_starts_with($key, '#') && is_array($values)) {
                $tagName = substr($key, 1);
                $relayFilters['#' . $tagName] = $values;
            }
        }

        try {
            return $this->relayClient->fetch(
                kinds: $kinds,
                filter: $relayFilters,
                relayUrls: $filter['relays'] ?? [],
            );
        } catch (\Throwable) {
            return [];
        }
    }
}
