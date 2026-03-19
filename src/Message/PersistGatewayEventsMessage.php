<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched by the relay gateway after a query correlation completes.
 *
 * Contains raw Nostr event arrays received from external relays so they
 * can be persisted to the local Event table asynchronously by the worker.
 *
 * Handler: App\MessageHandler\PersistGatewayEventsHandler
 */
class PersistGatewayEventsMessage
{
    /**
     * @param array<int, array<string, mixed>> $events  Raw event arrays (id, kind, pubkey, content, created_at, tags, sig)
     */
    public function __construct(
        public readonly array $events,
    ) {}
}

