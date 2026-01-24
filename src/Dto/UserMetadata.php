<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Data Transfer Object for Nostr user profile metadata (kind:0).
 * Ensures consistent structure across the application.
 */
class UserMetadata
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $displayName = null,
        public readonly ?string $picture = null,
        public readonly ?string $banner = null,
        public readonly ?string $about = null,
        public readonly array $website = [],
        public readonly array $lud16 = [],
        public readonly array $lud06 = [],
        public readonly array $nip05 = [],
        public readonly bool $bot = false,
    ) {}

    /**
     * Create from stdClass (legacy cache format).
     * Handles array values and string values for multi-value fields.
     */
    public static function fromStdClass(\stdClass $data): self
    {
        return new self(
            name: self::extractString($data, 'name'),
            displayName: self::extractString($data, 'display_name') ?? self::extractString($data, 'displayName'),
            picture: self::extractString($data, 'picture'),
            banner: self::extractString($data, 'banner'),
            about: self::extractString($data, 'about'),
            website: self::extractArray($data, 'website'),
            lud16: self::extractArray($data, 'lud16'),
            lud06: self::extractArray($data, 'lud06'),
            nip05: self::extractArray($data, 'nip05'),
            bot: isset($data->bot) ? (bool)$data->bot : false,
        );
    }

    /**
     * Create default metadata for a given pubkey (used when no metadata is cached).
     */
    public static function createDefault(string $pubkeyHex): self
    {
        $npub = \App\Util\NostrKeyUtil::hexToNpub($pubkeyHex);
        $defaultName = '@' . substr($npub, 5, 4) . '…' . substr($npub, -4);

        return new self(name: $defaultName);
    }

    /**
     * Convert to stdClass for backward compatibility with templates.
     */
    public function toStdClass(): \stdClass
    {
        $obj = new \stdClass();
        $obj->name = $this->name;
        $obj->display_name = $this->displayName;
        $obj->picture = $this->picture;
        $obj->banner = $this->banner;
        $obj->about = $this->about;
        $obj->website = $this->website;
        $obj->lud16 = $this->lud16;
        $obj->lud06 = $this->lud06;
        $obj->nip05 = $this->nip05;
        $obj->bot = $this->bot;

        return $obj;
    }

    /**
     * Extract array values from stdClass, normalizing strings to single-element arrays.
     */
    private static function extractArray(\stdClass $data, string $property): array
    {
        if (!isset($data->$property)) {
            return [];
        }

        $value = $data->$property;

        // Handle array values (from Nostr tags)
        if (is_array($value)) {
            return array_values(array_filter($value, fn($v) => is_string($v) && $v !== ''));
        }

        // Handle string values - convert to single-element array
        if (is_string($value) && $value !== '') {
            return [$value];
        }

        return [];
    }

    /**
     * Extract a string value from stdClass, handling arrays by taking first element.
     */
    private static function extractString(\stdClass $data, string $property): ?string
    {
        if (!isset($data->$property)) {
            return null;
        }

        $value = $data->$property;

        // Handle array values (from Nostr tags)
        if (is_array($value)) {
            return !empty($value) ? (string)$value[0] : null;
        }

        // Handle string values
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    /**
     * Get the display name (prefers displayName, falls back to name).
     */
    public function getDisplayName(): ?string
    {
        return $this->displayName ?? $this->name;
    }

    /**
     * Check if the user has a lightning address configured.
     */
    public function hasLightningAddress(): bool
    {
        return !empty($this->lud16) || !empty($this->lud06);
    }

    /**
     * Get the primary lightning address (prefers lud16 over lud06).
     * Returns the first element if multiple addresses exist.
     */
    public function getLightningAddress(): ?string
    {
        if (!empty($this->lud16)) {
            return $this->lud16[0];
        }
        if (!empty($this->lud06)) {
            return $this->lud06[0];
        }
        return null;
    }

    /**
     * Get all lightning addresses (both lud16 and lud06).
     */
    public function getAllLightningAddresses(): array
    {
        return array_merge($this->lud16, $this->lud06);
    }

    /**
     * Get the primary website URL.
     * Returns the first element if multiple websites exist.
     */
    public function getWebsite(): ?string
    {
        return !empty($this->website) ? $this->website[0] : null;
    }

    /**
     * Get the primary NIP-05 identifier.
     * Returns the first element if multiple identifiers exist.
     */
    public function getNip05(): ?string
    {
        return !empty($this->nip05) ? $this->nip05[0] : null;
    }
}
