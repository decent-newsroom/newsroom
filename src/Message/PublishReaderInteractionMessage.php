<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Deliver one already-committed, durable reader interaction (comment/reply,
 * like, or repost) to its resolved relay set.
 *
 * Carries only the event id (not the payload) — the handler always re-reads
 * current state from {@see \App\Unfold\InteractionOutboxStore}, which makes
 * re-dispatch from the recovery command ({@see \App\Command\DispatchReaderInteractionsCommand})
 * safe even when the outbox row has changed (status, attempts, relays) since
 * this message was first produced.
 */
final class PublishReaderInteractionMessage
{
    public function __construct(
        private readonly string $eventId,
        private readonly ?string $dispatchLease = null,
    ) {
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getDispatchLease(): ?string
    {
        return $this->dispatchLease;
    }
}
