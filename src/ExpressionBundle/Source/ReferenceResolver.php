<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Source;

use App\ExpressionBundle\Exception\InvalidArgumentException;
use App\ExpressionBundle\Exception\UnresolvedRefException;
use App\Repository\EventRepository;

/**
 * Resolves NIP-FX `in` references for pubkey/tag domains.
 */
final class ReferenceResolver
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly PubkeyListSourceResolver $pubkeyListSourceResolver,
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
                3, 39089 => $this->extractPubkeysFromReference($kind, $pubkey, $d),
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
    private function extractPubkeysFromReference(int $kind, string $pubkey, string $d): array
    {
        try {
            return $this->pubkeyListSourceResolver->resolvePubkeysByAddress("{$kind}:{$pubkey}:{$d}");
        } catch (UnresolvedRefException) {
            return [];
        }
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

