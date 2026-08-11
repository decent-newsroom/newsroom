<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use DecentNewsroom\NostrKernelBundle\Contract\Event\EventNormalizerInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;
use DecentNewsroom\NostrKernelBundle\Exception\InvalidNostrEvent;

final readonly class NostrEventIngressGuard
{
    public function __construct(
        private EventNormalizerInterface $normalizer,
    ) {
    }

    /**
     * @param array<string, mixed> $rawEvent
     */
    public function normalizeArray(array $rawEvent): NostrEvent
    {
        return $this->normalizer->normalize($rawEvent);
    }

    public function normalizeObject(object $event): NostrEvent
    {
        $rawEvent = \method_exists($event, 'toArray')
            ? $event->toArray()
            : \get_object_vars($event);

        if (!\is_array($rawEvent)) {
            throw new InvalidNostrEvent('Nostr event could not be converted to an array.');
        }

        return $this->normalizeArray($rawEvent);
    }
}
