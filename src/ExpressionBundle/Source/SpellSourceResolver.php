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
use Psr\Log\LoggerInterface;

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
    public function executeEvent(Event $spellEvent, RuntimeContext $ctx): array
    {
        $label = $spellEvent->getId() ?: 'unknown';
        $this->logger->debug('Executing spell from pre-resolved event', ['eventId' => $label]);

        return $this->executeSpell($spellEvent, $label, $ctx);
    }

    /** @return NormalizedItem[] */
    private function executeSpell(Event $spellEvent, string $label, RuntimeContext $ctx): array
    {
        $start = microtime(true);
        $filter = $this->spellParser->parse($spellEvent, $ctx);
        $hasExplicitRelays = !empty($filter['relays']);

        $this->logger->debug('Spell filter built', [
            'label' => $label,
            'kinds' => $filter['kinds'] ?? [],
            'limit' => $filter['limit'] ?? null,
            'relays' => $filter['relays'] ?? [],
        ]);

        if ($hasExplicitRelays) {
            // Explicit relays: query them directly — they are the authoritative source.
            $this->logger->debug('Spell has explicit relays, querying them directly', [
                'label' => $label,
                'relays' => $filter['relays'],
            ]);
            $events = $this->fetchFromRelays($filter);
            $source = 'relays';

            // DB fallback if relays returned nothing
            if (empty($events)) {
                $this->logger->debug('Spell relays returned empty, falling back to DB', [
                    'label' => $label,
                ]);
                $events = $this->eventRepository->findByFilter($filter);
                $source = 'db-fallback';
            }
        } else {
            // No explicit relays: DB-first, then content relay fallback.
            $events = $this->eventRepository->findByFilter($filter);
            $source = 'db';

            if (empty($events)) {
                $this->logger->debug('Spell DB empty, falling back to content relays', [
                    'label' => $label,
                ]);
                $events = $this->fetchFromRelays($filter);
                $source = 'relays-fallback';
            }
        }

        $ms = round((microtime(true) - $start) * 1000);
        $this->logger->info('Spell resolved', [
            'label' => $label,
            'source' => $source,
            'events' => count($events),
            'ms' => $ms,
        ]);

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

