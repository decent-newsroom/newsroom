<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Source;

use DecentNewsroom\ExpressionBundle\Exception\InvalidArgumentException;
use DecentNewsroom\ExpressionBundle\Exception\UnresolvedRefException;
use DecentNewsroom\ExpressionBundle\Model\RuntimeContext;
use DecentNewsroom\ExpressionBundle\Service\EventResolver;

/**
 * Resolves NIP-FX `in` references for pubkey/tag domains.
 */
final class ReferenceResolver
{
    public function __construct(
        private readonly EventResolver $eventResolver,
        private readonly PubkeyListSourceResolver $pubkeyListSourceResolver,
    ) {}

    /**
     * @return string[] Expanded comparison values (pubkeys or tags)
     */
    public function resolveForDomain(string $reference, string $domain, ?RuntimeContext $ctx = null): array
    {
        [$kind, $pubkey, $d] = explode(':', $reference, 3);
        $kind = (int) $kind;

        return match ($domain) {
            'pubkey' => match ($kind) {
                3, 39089 => $this->extractPubkeysFromReference($kind, $pubkey, $d, $ctx),
                default => throw new InvalidArgumentException("Kind {$kind} not valid for pubkey domain"),
            },
            'tag' => match ($kind) {
                30015 => $this->extractTagsFromInterestSet($reference, $ctx),
                default => throw new InvalidArgumentException("Kind {$kind} not valid for tag domain"),
            },
            default => throw new InvalidArgumentException("Unknown reference domain: {$domain}"),
        };
    }

    /** @return string[] */
    private function extractPubkeysFromReference(int $kind, string $pubkey, string $d, ?RuntimeContext $ctx): array
    {
        try {
            return $this->pubkeyListSourceResolver->resolvePubkeysByAddress("{$kind}:{$pubkey}:{$d}", $ctx);
        } catch (UnresolvedRefException) {
            return [];
        }
    }

    /** @return string[] */
    private function extractTagsFromInterestSet(string $reference, ?RuntimeContext $ctx): array
    {
        [$kind, $pubkey, $d] = explode(':', $reference, 3);
        $event = $this->eventResolver->findByNaddr((int) $kind, $pubkey, $d, $ctx);
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
