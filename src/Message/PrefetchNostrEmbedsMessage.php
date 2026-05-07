<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched when an article page is served and some of its nostr: embed
 * references could not be resolved from the local database.
 *
 * The handler fetches the missing events from relays in bulk and projects
 * them into the local DB so that subsequent page views render them fully.
 */
final class PrefetchNostrEmbedsMessage
{
    /**
     * @param string   $articleCoordinate  e.g. "30023:pubkey:slug" — used for logging / relay hints
     * @param string[] $eventIds           Hex event IDs that need fetching (from note/nevent refs)
     * @param string[] $coordinates        "kind:pubkey:d-tag" coordinate strings (from naddr refs)
     * @param string[] $relayHints         Optional relay URLs hinted by the bech32 payloads
     */
    public function __construct(
        private readonly string $articleCoordinate,
        private readonly array  $eventIds,
        private readonly array  $coordinates,
        private readonly array  $relayHints = [],
    ) {}

    public function getArticleCoordinate(): string
    {
        return $this->articleCoordinate;
    }

    /** @return string[] */
    public function getEventIds(): array
    {
        return $this->eventIds;
    }

    /** @return string[] */
    public function getCoordinates(): array
    {
        return $this->coordinates;
    }

    /** @return string[] */
    public function getRelayHints(): array
    {
        return $this->relayHints;
    }

    public function isEmpty(): bool
    {
        return empty($this->eventIds) && empty($this->coordinates);
    }
}

