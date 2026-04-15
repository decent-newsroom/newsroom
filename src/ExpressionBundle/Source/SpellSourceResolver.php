<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Source;

use App\Entity\Event;
use App\ExpressionBundle\Exception\UnresolvedRefException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Parser\SpellParser;
use App\Repository\EventRepository;
use App\Service\Nostr\NostrRequestExecutor;
use App\Service\Nostr\RelaySetFactory;

/**
 * Executes kind:777 spells: parse → build filter → query DB/relays → NormalizedItem[].
 */
final class SpellSourceResolver
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly SpellParser $spellParser,
        private readonly NostrRequestExecutor $requestExecutor,
        private readonly RelaySetFactory $relaySetFactory,
    ) {}

    /** @return NormalizedItem[] */
    public function resolve(string $spellAddress, RuntimeContext $ctx): array
    {
        $spellEvent = $this->findEvent($spellAddress);
        $filter = $this->spellParser->parse($spellEvent, $ctx);

        // DB-first
        $events = $this->eventRepository->findByFilter($filter);

        // Relay fallback if needed
        if (empty($events) && isset($filter['relays'])) {
            $events = $this->fetchFromRelays($filter);
        } elseif (empty($events)) {
            // Fallback to content relays
            $events = $this->fetchFromRelays($filter);
        }

        return array_map(fn(Event $e) => new NormalizedItem($e), $events);
    }

    private function findEvent(string $address): Event
    {
        [$kind, $pubkey, $d] = explode(':', $address, 3);
        $event = $this->eventRepository->findByNaddr((int) $kind, $pubkey, $d);
        if ($event === null) {
            throw new UnresolvedRefException("Spell not found: {$address}");
        }
        return $event;
    }

    /** @return Event[] */
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
                $relayFilters['tag'] = [$tagName, $values];
            }
        }

        try {
            $relaySet = isset($filter['relays'])
                ? $this->relaySetFactory->fromUrls($filter['relays'])
                : null;

            $rawEvents = $this->requestExecutor->fetch(
                kinds: $kinds,
                filters: $relayFilters,
                relaySet: $relaySet,
            );

            return array_map(fn(object $raw) => $this->convertToEntity($raw), $rawEvents);
        } catch (\Throwable) {
            return [];
        }
    }

    private function convertToEntity(object $raw): Event
    {
        $event = new Event();
        $event->setId($raw->id ?? '');
        $event->setPubkey($raw->pubkey ?? '');
        $event->setKind((int) ($raw->kind ?? 0));
        $event->setContent($raw->content ?? '');
        $event->setCreatedAt((int) ($raw->created_at ?? 0));
        $event->setTags((array) ($raw->tags ?? []));
        $event->setSig($raw->sig ?? '');
        return $event;
    }
}



