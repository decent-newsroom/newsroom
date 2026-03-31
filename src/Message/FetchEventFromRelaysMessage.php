<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched when an event is not found in the local database and needs
 * to be fetched from Nostr relays asynchronously.  The handler will query
 * relays, persist the event, and publish a Mercure update so the waiting
 * browser can reload.
 */
final class FetchEventFromRelaysMessage
{
    /**
     * @param string      $lookupKey  Unique key for this fetch (e.g. "naddr:30023:<pubkey>:<slug>")
     * @param string      $type       Lookup type: "naddr" | "nevent" | "note"
     * @param int|null    $kind       Event kind (naddr only)
     * @param string|null $pubkey     Author hex pubkey (naddr: required; nevent/note: used for author relay list resolution)
     * @param string|null $identifier d-tag / slug (naddr only)
     * @param string|null $eventId    Event ID (nevent/note only)
     * @param string[]    $relays     Relay hint URLs
     */
    public function __construct(
        public readonly string  $lookupKey,
        public readonly string  $type,
        public readonly ?int    $kind = null,
        public readonly ?string $pubkey = null,
        public readonly ?string $identifier = null,
        public readonly ?string $eventId = null,
        public readonly array   $relays = [],
    ) {}
}

