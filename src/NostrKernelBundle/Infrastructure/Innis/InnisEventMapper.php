<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Infrastructure\Innis;

use DecentNewsroom\NostrKernelBundle\Domain\Event\EventId;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventKind;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventSignature;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventTags;
use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;
use DecentNewsroom\NostrKernelBundle\Domain\Identity\Pubkey;
use DecentNewsroom\NostrKernelBundle\Exception\InvalidNostrEvent;
use Innis\Nostr\Core\Domain\Entity\Event as InnisEvent;

final readonly class InnisEventMapper
{
    /**
     * @param array<string, mixed> $rawEvent
     */
    public function map(array $rawEvent): NostrEvent
    {
        try {
            $innisEvent = InnisEvent::fromArray($rawEvent);
            $normalized = $innisEvent->toArray();
        } catch (\Throwable $e) {
            throw new InvalidNostrEvent(
                message: 'Failed to hydrate Nostr event through innis/nostr-core.',
                previous: $e,
            );
        }

        return new NostrEvent(
            kind: new EventKind($normalized['kind']),
            pubkey: new Pubkey($normalized['pubkey']),
            tags: EventTags::fromRaw($normalized['tags']),
            content: $normalized['content'],
            createdAt: $normalized['created_at'],
            id: new EventId($normalized['id']),
            signature: new EventSignature($normalized['sig']),
        );
     }
}

