<?php

declare(strict_types=1);

namespace App\Message;

use App\Enum\AuthorContentType;

/**
 * Message to fetch multiple content types for an author from their home relays
 */
class FetchAuthorContentMessage
{
    /**
     * @param string $pubkey Hex public key of the author
     * @param AuthorContentType[] $contentTypes Content types to fetch
     * @param int $since Unix timestamp to fetch content since (0 = all time)
     * @param bool $isOwner Whether the requester is the profile owner (for private content)
     * @param string[]|null $relays Override relay list (null = auto-discover via NIP-65)
     */
    public function __construct(
        private readonly string $pubkey,
        private readonly array $contentTypes,
        private readonly int $since = 0,
        private readonly bool $isOwner = false,
        private readonly ?array $relays = null
    ) {}

    public function getPubkey(): string
    {
        return $this->pubkey;
    }

    /**
     * @return AuthorContentType[]
     */
    public function getContentTypes(): array
    {
        return $this->contentTypes;
    }

    public function getSince(): int
    {
        return $this->since;
    }

    public function isOwner(): bool
    {
        return $this->isOwner;
    }

    /**
     * @return string[]|null
     */
    public function getRelays(): ?array
    {
        return $this->relays;
    }

    /**
     * Get all Nostr kinds to fetch based on content types
     * @return int[]
     */
    public function getKinds(): array
    {
        return AuthorContentType::kindsForTypes($this->contentTypes);
    }

    /**
     * Get only the content types that should be fetched based on ownership
     * @return AuthorContentType[]
     */
    public function getEffectiveContentTypes(): array
    {
        return array_filter(
            $this->contentTypes,
            fn(AuthorContentType $type) => !$type->requiresOwnership() || $this->isOwner
        );
    }
}
