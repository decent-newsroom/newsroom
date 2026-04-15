<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Source;

use App\ExpressionBundle\Exception\InvalidArgumentException;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\Repository\EventRepository;

/**
 * Resolves NIP-FX `in` references: follow packs (kind 39089) and interest sets (kind 30015).
 */
final class ReferenceResolver
{
    public function __construct(
        private readonly EventRepository $eventRepository,
    ) {}

    /**
     * @return string[] Expanded comparison values (pubkeys or tags)
     */
    public function resolveForDomain(string $reference, string $domain): array
    {
        [$kind, $pubkey, $d] = explode(':', $reference, 3);
        $kind = (int) $kind;

        return match ($domain) {
            'pubkey' => match ($kind) {
                39089 => $this->extractPubkeysFromFollowPack($reference),
                default => throw new InvalidArgumentException("Kind {$kind} not valid for pubkey domain"),
            },
            'tag' => match ($kind) {
                30015 => $this->extractTagsFromInterestSet($reference),
                default => throw new InvalidArgumentException("Kind {$kind} not valid for tag domain"),
            },
            default => throw new InvalidArgumentException("Unknown reference domain: {$domain}"),
        };
    }

    /** @return string[] */
    private function extractPubkeysFromFollowPack(string $reference): array
    {
        [$kind, $pubkey, $d] = explode(':', $reference, 3);
        $event = $this->eventRepository->findByNaddr((int) $kind, $pubkey, $d);
        if ($event === null) {
            return [];
        }

        $pubkeys = [];
        foreach ($event->getTags() as $tag) {
            if (($tag[0] ?? '') === 'p' && isset($tag[1])) {
                $pubkeys[] = $tag[1];
            }
        }
        return $pubkeys;
    }

    /** @return string[] */
    private function extractTagsFromInterestSet(string $reference): array
    {
        [$kind, $pubkey, $d] = explode(':', $reference, 3);
        $event = $this->eventRepository->findByNaddr((int) $kind, $pubkey, $d);
        if ($event === null) {
            return [];
        }

        $tags = [];
        foreach ($event->getTags() as $tag) {
            if (($tag[0] ?? '') === 't' && isset($tag[1])) {
                $tags[] = $tag[1];
            }
        }
        return $tags;
    }
}

