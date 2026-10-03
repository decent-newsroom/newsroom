<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

interface SignedInteractionPublisherInterface
{
    /** @param array<string, mixed> $signedEvent */
    public function publish(array $signedEvent, InteractionTarget $target, string $readerPubkey): InteractionDelivery;

    public function status(string $eventId, InteractionTarget $target, string $readerPubkey): ?InteractionDelivery;

    /** Requeue only an existing event owned by this reader and targeting this leaf. */
    public function retry(string $eventId, InteractionTarget $target, string $readerPubkey): InteractionDelivery;
}
