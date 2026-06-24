<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Application\Event;

use DecentNewsroom\NostrKernelBundle\Contract\Event\EventKindClassifierInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Event\EventNormalizerInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Event\EventValidatorInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventId;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventSignature;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventTags;
use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;
use DecentNewsroom\NostrKernelBundle\Domain\Identity\Pubkey;
use DecentNewsroom\NostrKernelBundle\Exception\InvalidNostrEvent;

final readonly class ValidateAndNormalizeEvent implements EventNormalizerInterface
{
    public function __construct(
        private EventValidatorInterface $validator,
        private EventKindClassifierInterface $kindClassifier,
        private bool $strictValidation = true,
        private int $allowFutureEventsSeconds = 300,
        private bool $verifySignatures = true,
        private bool $allowProtectedEvents = true,
    ) {
    }

    public function normalize(array $rawEvent): NostrEvent
    {
        $kind = $rawEvent['kind'] ?? null;
        $pubkey = $rawEvent['pubkey'] ?? null;

        if (!\is_int($kind) && !\is_numeric($kind)) {
            throw new InvalidNostrEvent('Event kind is required and must be numeric.');
        }

        if (!\is_string($pubkey)) {
            throw new InvalidNostrEvent('Event pubkey is required.');
        }

        $tags = $rawEvent['tags'] ?? [];
        if (!\is_array($tags)) {
            throw new InvalidNostrEvent('Event tags must be an array.');
        }

        $event = new NostrEvent(
            kind: $this->kindClassifier->classify((int) $kind),
            pubkey: new Pubkey($pubkey),
            tags: EventTags::fromRaw($tags),
            content: \is_string($rawEvent['content'] ?? null) ? $rawEvent['content'] : '',
            createdAt: (int) ($rawEvent['created_at'] ?? 0),
            id: \is_string($rawEvent['id'] ?? null) ? new EventId($rawEvent['id']) : null,
            signature: \is_string($rawEvent['sig'] ?? null) ? new EventSignature($rawEvent['sig']) : null,
        );

        $result = $this->validator->validate($event);
        if ($this->strictValidation && !$result->isValid()) {
            throw new InvalidNostrEvent('Event validation failed: ' . \implode('; ', $result->errors()));
        }

        return $event;
    }
}

