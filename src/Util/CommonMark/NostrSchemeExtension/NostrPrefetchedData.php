<?php

declare(strict_types=1);

namespace App\Util\CommonMark\NostrSchemeExtension;

/**
 * Holds pre-fetched Nostr data (metadata + events) so that
 * inline parsers can read from memory instead of making
 * individual network calls per mention.
 */
class NostrPrefetchedData
{
    /**
     * @param array<string, object|null> $metadataByHex  hex pubkey => UserMetadata|null
     * @param array<string, object|null> $eventsById     event id  => event object|null
     * @param array<string, object|null> $eventsByNaddr  "kind:pubkey:d-tag" => event object|null
     */
    public function __construct(
        private array $metadataByHex = [],
        private array $eventsById = [],
        private array $eventsByNaddr = [],
    ) {}

    public function getMetadata(string $hex): ?object
    {
        return $this->metadataByHex[$hex] ?? null;
    }

    public function hasMetadata(string $hex): bool
    {
        return array_key_exists($hex, $this->metadataByHex);
    }

    public function getEvent(string $id): ?object
    {
        return $this->eventsById[$id] ?? null;
    }

    public function hasEvent(string $id): bool
    {
        return array_key_exists($id, $this->eventsById);
    }

    public function getEventByNaddr(string $coordKey): ?object
    {
        return $this->eventsByNaddr[$coordKey] ?? null;
    }

    public function hasEventByNaddr(string $coordKey): bool
    {
        return array_key_exists($coordKey, $this->eventsByNaddr);
    }

    /** @return array<string, object|null> */
    public function getAllMetadata(): array
    {
        return $this->metadataByHex;
    }

    /** @return array<string, object|null> */
    public function getAllEvents(): array
    {
        return $this->eventsById;
    }

    /** @return array<string, object|null> */
    public function getAllNaddrEvents(): array
    {
        return $this->eventsByNaddr;
    }
}

