<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Source;

use DecentNewsroom\ExpressionBundle\Contract\EventInterface;
use DecentNewsroom\ExpressionBundle\Exception\UnresolvedRefException;
use DecentNewsroom\ExpressionBundle\Model\NormalizedItem;
use DecentNewsroom\ExpressionBundle\Model\RuntimeContext;
use DecentNewsroom\ExpressionBundle\Parser\SpellParser;
use DecentNewsroom\ExpressionBundle\Service\EventResolver;
use Psr\Log\LoggerInterface;

/**
 * Executes kind:777 spells: parse → build filter → query local store/relays.
 */
final class SpellSourceResolver
{
    public function __construct(
        private readonly EventResolver $eventResolver,
        private readonly SpellParser $spellParser,
        private readonly LoggerInterface $logger,
    ) {}

    /** @return NormalizedItem[] */
    public function resolve(string $spellAddress, RuntimeContext $ctx): array
    {
        $this->logger->debug('Resolving spell by address', ['address' => $spellAddress]);

        $spellEvent = $this->findEvent($spellAddress, $ctx);

        return $this->executeSpell($spellEvent, $spellAddress, $ctx);
    }

    /**
     * Execute a spell from an already-resolved Event (skips event lookup).
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

        $this->logger->debug('Spell filter built', [
            'label' => $label,
            'kinds' => $filter['kinds'] ?? [],
            'limit' => $filter['limit'] ?? null,
            'spellRelays' => $filter['relays'] ?? [],
            'userRelays' => $ctx->relays,
        ]);

        $events = $this->eventResolver->findByFilter($filter, $ctx);

        // Optional: honor the spell's limit on the merged result set.
        if (!empty($filter['limit']) && count($events) > (int) $filter['limit']) {
            // Keep newest first by created_at.
            usort($events, static fn(EventInterface $a, EventInterface $b) => $b->getCreatedAt() <=> $a->getCreatedAt());
            $events = array_slice($events, 0, (int) $filter['limit']);
        }

        $ms = round((microtime(true) - $start) * 1000);
        $this->logger->info('Spell resolved', [
            'label' => $label,
            'source' => 'local-store+relays',
            'events' => count($events),
            'ms' => $ms,
        ]);

        return array_map(fn(EventInterface $e) => new NormalizedItem($e), $events);
    }

    private function findEvent(string $address, ?RuntimeContext $ctx = null): EventInterface
    {
        [$kind, $pubkey, $d] = explode(':', $address, 3);
        $event = $this->eventResolver->findByNaddr((int) $kind, $pubkey, $d, $ctx);
        if ($event === null) {
            throw new UnresolvedRefException("Spell not found: {$address}");
        }
        return $event;
    }

}
