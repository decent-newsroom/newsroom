<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Broadcast an already-validated, locally-persisted reaction (kind 7) to the
 * author's and reactor's write relays outside the web request cycle.
 *
 * Relay publishing is blocking network I/O; doing it synchronously in the
 * request can exceed PHP's max execution time and produce an uncatchable
 * fatal. The reaction is persisted and counted locally before dispatch, so
 * the relay fan-out can happen asynchronously without the user waiting.
 */
class PublishReactionMessage
{
    /**
     * @param array<string, mixed> $signedEvent The verified signed reaction event
     * @param string[]             $relays      Target relay URLs
     */
    public function __construct(
        private readonly array $signedEvent,
        private readonly array $relays,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getSignedEvent(): array
    {
        return $this->signedEvent;
    }

    /**
     * @return string[]
     */
    public function getRelays(): array
    {
        return $this->relays;
    }
}
