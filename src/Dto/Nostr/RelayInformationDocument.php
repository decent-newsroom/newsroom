<?php

declare(strict_types=1);

namespace App\Dto\Nostr;

/**
 * Strict-ish parser for a NIP-11 Relay Information Document JSON payload.
 *
 * Accepts the full schema described in
 * https://github.com/nostr-protocol/nips/blob/master/11.md including the
 * nested `limitation`, `retention` and `fees` objects. Unknown fields are
 * ignored. Type-mismatched fields are dropped (logged by the caller) rather
 * than throwing — relays in the wild routinely emit string-typed booleans,
 * single-element rather than array-typed `language_tags`, etc.
 */
final readonly class RelayInformationDocument
{
    /**
     * @param int[]                       $supportedNips
     * @param array<string,mixed>|null    $limitation
     * @param string[]|null               $relayCountries
     * @param string[]|null               $languageTags
     * @param string[]|null               $tags
     * @param array<string,mixed>|null    $fees
     */
    public function __construct(
        public ?string $name,
        public ?string $description,
        public ?string $pubkey,
        public ?string $contact,
        public ?string $software,
        public ?string $version,
        public array $supportedNips,
        public ?array $limitation,
        public ?array $relayCountries,
        public ?array $languageTags,
        public ?array $tags,
        public ?string $postingPolicy,
        public ?string $paymentsUrl,
        public ?string $icon,
        public ?array $fees,
        public bool $authRequired,
    ) {}

    /**
     * @param array<string,mixed> $json
     */
    public static function fromArray(array $json): self
    {
        $limitation = self::asArrayOrNull($json['limitation'] ?? null);

        $authRequired = false;
        if ($limitation !== null && isset($limitation['auth_required'])) {
            $authRequired = filter_var($limitation['auth_required'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
        }

        return new self(
            name: self::asStringOrNull($json['name'] ?? null),
            description: self::asStringOrNull($json['description'] ?? null),
            pubkey: self::asStringOrNull($json['pubkey'] ?? null),
            contact: self::asStringOrNull($json['contact'] ?? null),
            software: self::asStringOrNull($json['software'] ?? null),
            version: self::asStringOrNull($json['version'] ?? null),
            supportedNips: self::asIntList($json['supported_nips'] ?? []),
            limitation: $limitation,
            relayCountries: self::asStringListOrNull($json['relay_countries'] ?? null),
            languageTags: self::asStringListOrNull($json['language_tags'] ?? null),
            tags: self::asStringListOrNull($json['tags'] ?? null),
            postingPolicy: self::asStringOrNull($json['posting_policy'] ?? null),
            paymentsUrl: self::asStringOrNull($json['payments_url'] ?? null),
            icon: self::asStringOrNull($json['icon'] ?? null),
            fees: self::asArrayOrNull($json['fees'] ?? null),
            authRequired: $authRequired,
        );
    }

    private static function asStringOrNull(mixed $v): ?string
    {
        if ($v === null) return null;
        if (is_string($v)) {
            $t = trim($v);
            return $t === '' ? null : $t;
        }
        if (is_scalar($v)) return (string) $v;
        return null;
    }

    /**
     * @param mixed $v
     * @return int[]
     */
    private static function asIntList(mixed $v): array
    {
        if (!is_array($v)) return [];
        $out = [];
        foreach ($v as $item) {
            if (is_int($item)) {
                $out[] = $item;
            } elseif (is_numeric($item)) {
                $out[] = (int) $item;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param mixed $v
     * @return string[]|null
     */
    private static function asStringListOrNull(mixed $v): ?array
    {
        if ($v === null) return null;
        if (is_string($v)) {
            return [$v];
        }
        if (!is_array($v)) return null;
        $out = [];
        foreach ($v as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }
        return $out === [] ? null : $out;
    }

    /**
     * @param mixed $v
     * @return array<string,mixed>|null
     */
    private static function asArrayOrNull(mixed $v): ?array
    {
        return is_array($v) ? $v : null;
    }
}

