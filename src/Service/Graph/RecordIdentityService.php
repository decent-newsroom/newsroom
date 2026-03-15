<?php

declare(strict_types=1);

namespace App\Service\Graph;

use App\Enum\KindsEnum;

/**
 * Single authority for all identity derivation in the graph layer.
 *
 * No other service, controller, or command should independently compute
 * coordinates, record UIDs, or entity types. All canonical identity logic
 * goes through this service.
 */
class RecordIdentityService
{
    /**
     * Check if a kind is parameterized replaceable (30000–39999).
     */
    public function isParameterizedReplaceable(int $kind): bool
    {
        return $kind >= 30000 && $kind <= 39999;
    }

    /**
     * Check if a kind is replaceable (0, 3, or 10000–19999).
     */
    public function isReplaceable(int $kind): bool
    {
        return $kind === 0 || $kind === 3 || ($kind >= 10000 && $kind <= 19999);
    }

    /**
     * Derive the canonical coordinate string for a parameterized replaceable event.
     *
     * Format: "<kind>:<pubkey>:<d_tag>"
     *
     * Returns null if the event is not parameterized replaceable.
     */
    public function deriveCoordinate(int $kind, string $pubkey, ?string $dTag): ?string
    {
        if (!$this->isParameterizedReplaceable($kind)) {
            return null;
        }

        // Normalize: absent d tag treated as empty string
        $normalizedDTag = $dTag ?? '';

        return $kind . ':' . strtolower($pubkey) . ':' . $normalizedDTag;
    }

    /**
     * Derive the canonical coordinate for a replaceable (non-parameterized) event.
     *
     * Format: "<kind>:<pubkey>"
     */
    public function deriveReplaceableCoordinate(int $kind, string $pubkey): ?string
    {
        if (!$this->isReplaceable($kind)) {
            return null;
        }

        return $kind . ':' . strtolower($pubkey);
    }

    /**
     * Generate the record UID for a stable identity.
     *
     * - Parameterized replaceable: "coord:<kind>:<pubkey>:<d_tag>"
     * - Replaceable: "coord:<kind>:<pubkey>"
     * - Immutable event: "event:<event_id>"
     */
    public function deriveRecordUid(int $kind, string $pubkey, ?string $dTag = null, ?string $eventId = null): string
    {
        if ($this->isParameterizedReplaceable($kind)) {
            return 'coord:' . $this->deriveCoordinate($kind, $pubkey, $dTag);
        }

        if ($this->isReplaceable($kind)) {
            return 'coord:' . $this->deriveReplaceableCoordinate($kind, $pubkey);
        }

        // Immutable event
        if ($eventId !== null) {
            return 'event:' . $eventId;
        }

        throw new \InvalidArgumentException(
            'Cannot derive record UID: non-replaceable event requires an event ID'
        );
    }

    /**
     * Determine the ref_type for a reference.
     *
     * @return string 'coordinate' or 'event'
     */
    public function deriveRefType(int $kind): string
    {
        if ($this->isParameterizedReplaceable($kind) || $this->isReplaceable($kind)) {
            return 'coordinate';
        }

        return 'event';
    }

    /**
     * Derive the entity_type hint from a Nostr event kind.
     *
     * This is an optimization hint, not the primary identity.
     */
    public function deriveEntityType(int $kind): string
    {
        return match ($kind) {
            KindsEnum::PUBLICATION_INDEX->value => 'magazine',  // 30040 — may also be category/reading_list
            KindsEnum::PUBLICATION_CONTENT->value => 'chapter', // 30041
            KindsEnum::LONGFORM->value => 'article',            // 30023
            KindsEnum::LONGFORM_DRAFT->value => 'draft',        // 30024
            KindsEnum::CURATION_SET->value,
            KindsEnum::CURATION_VIDEOS->value,
            KindsEnum::CURATION_PICTURES->value => 'curation_set',
            KindsEnum::BOOKMARK_SETS->value => 'bookmark_set',
            KindsEnum::APP_DATA->value => 'app_data',           // 30078
            default => 'unknown',
        };
    }

    /**
     * Decompose an "a" tag value into its coordinate components.
     *
     * An "a" tag has the format: "<kind>:<pubkey>:<d_tag>"
     *
     * @return array{kind: int, pubkey: string, d_tag: string}|null
     */
    public function decomposeATag(string $aTagValue): ?array
    {
        $parts = explode(':', $aTagValue, 3);

        if (count($parts) < 2) {
            return null;
        }

        $kind = (int) $parts[0];
        $pubkey = strtolower($parts[1]);
        $dTag = $parts[2] ?? '';

        if ($kind <= 0 || empty($pubkey)) {
            return null;
        }

        return [
            'kind' => $kind,
            'pubkey' => $pubkey,
            'd_tag' => $dTag,
        ];
    }

    /**
     * Build a canonical coordinate string from an "a" tag value.
     *
     * Normalizes pubkey to lowercase.
     */
    public function canonicalizeATag(string $aTagValue): ?string
    {
        $parts = $this->decomposeATag($aTagValue);

        if ($parts === null) {
            return null;
        }

        return $parts['kind'] . ':' . $parts['pubkey'] . ':' . $parts['d_tag'];
    }
}

