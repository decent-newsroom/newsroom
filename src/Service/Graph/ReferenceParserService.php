<?php

declare(strict_types=1);

namespace App\Service\Graph;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Util\Nip10TagParser;

/**
 * Extracts DN-relevant references from event tags.
 *
 * Parses `a` coordinates, lowercase `e` event IDs and lowercase `p` pubkeys.
 * The parser does not resolve targets.
 * Returns normalized reference DTOs ready for insertion into parsed_reference.
 */
class ReferenceParserService
{
    /** Maximum serialized bytes of profile tag auxiliaries kept in the index. */
    private const MAX_PROFILE_AUXILIARY_BYTES = 4096;
    private const MAX_PROFILE_RELAY_BYTES = 2048;

    /** Kinds whose `a` tags represent structural containment (parent→child). */
    private const STRUCTURAL_SOURCE_KINDS = [
        30040, // PUBLICATION_INDEX — magazines, categories, reading lists
        30004, // CURATION_SET
        30005, // CURATION_VIDEOS
        30006, // CURATION_PICTURES
        30003, // BOOKMARK_SETS
        30045, // DIRECTORY — NKBIP-04 filesystem directory
    ];

    /** Kinds that the graph should track as resolvable targets. */
    private const RESOLVABLE_TARGET_KINDS = [
        30040, // PUBLICATION_INDEX
        30041, // PUBLICATION_CONTENT (chapters)
        30023, // LONGFORM (articles)
        30024, // LONGFORM_DRAFT
        30817, // CUSTOM_NIP
        30818, // WIKI
        30004, // CURATION_SET
        30005, // CURATION_VIDEOS
        30006, // CURATION_PICTURES
        30003, // BOOKMARK_SETS
        30045, // DIRECTORY
        30078, // APP_DATA
    ];

    public function __construct(
        private readonly RecordIdentityService $identityService,
    ) {}

    /**
     * Parse supported reference tags from an Event entity.
     *
     * @return ParsedReferenceDto[]
     */
    public function parseReferences(Event $event): array
    {
        return $this->parseFromTagsArray($event->getId(), $event->getKind(), $event->getTags());
    }

    /**
     * Parse supported reference tags from raw event data.
     *
     * @param string $eventId
     * @param int    $eventKind
     * @param array  $tags
     * @return ParsedReferenceDto[]
     */
    public function parseFromTagsArray(string $eventId, int $eventKind, array $tags): array
    {
        $refs = [];
        $position = 0;

        foreach ($tags as $tag) {
            $sourceTagPosition = $position++;
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }

            if (($tag[0] ?? null) === 'p') {
                $pubkey = $tag[1] ?? null;
                if (!is_string($pubkey) || !$this->isHex32($pubkey)) {
                    continue;
                }

                // Other kinds use this slot for non-relay values (e.g. reports).
                $candidate = $tag[2] ?? null;
                $relayHint = is_string($candidate)
                    && strlen($candidate) <= self::MAX_PROFILE_RELAY_BYTES
                    && Nip10TagParser::isWebSocketSchemeCandidate($candidate)
                    ? $candidate : null;
                $auxiliaryData = null;
                if (count($tag) > 2) {
                    $encoded = json_encode(array_values(array_slice($tag, 2)));
                    if ($encoded !== false && strlen($encoded) <= self::MAX_PROFILE_AUXILIARY_BYTES) {
                        $auxiliaryData = $encoded;
                    }
                }

                $refs[] = new ParsedReferenceDto(
                    sourceEventId: $eventId,
                    tagName: 'p',
                    targetRefType: 'profile',
                    targetKind: null,
                    targetPubkey: strtolower($pubkey),
                    targetDTag: null,
                    targetCoord: null,
                    relation: match ($eventKind) {
                        KindsEnum::FOLLOWS->value => 'follows',
                        KindsEnum::FOLLOW_PACK->value => 'member',
                        default => 'references',
                    },
                    marker: null,
                    position: $sourceTagPosition,
                    isStructural: false,
                    isResolvable: false,
                    relayHint: $relayHint,
                    auxiliaryData: $auxiliaryData,
                );

                continue;
            }

            if ($tag[0] === 'e') {
                $targetEventId = $tag[1];
                if (!is_string($targetEventId) || !$this->isHex32($targetEventId)) {
                    continue;
                }

                $relayHint = is_string($tag[2] ?? null) && $tag[2] !== '' ? $tag[2] : null;
                $marker = null;
                if ($eventKind === KindsEnum::TEXT_NOTE->value) {
                    $auxiliaries = Nip10TagParser::parseReferenceAuxiliaries($tag);
                    $marker = $auxiliaries['marker'];
                    $relayHint = $auxiliaries['relays'][0] ?? null;
                }

                $refs[] = new ParsedReferenceDto(
                    sourceEventId: $eventId,
                    tagName: 'e',
                    targetRefType: 'event_id',
                    targetKind: null,
                    targetPubkey: null,
                    targetDTag: null,
                    targetCoord: null,
                    relation: 'references',
                    marker: $marker,
                    position: $sourceTagPosition,
                    isStructural: false,
                    isResolvable: true,
                    targetEventId: strtolower($targetEventId),
                    relayHint: $relayHint,
                );

                continue;
            }

            if ($tag[0] !== 'a') {
                continue;
            }

            $aTagValue = $tag[1];
            if (!is_string($aTagValue)) {
                continue;
            }
            $decomposed = $this->identityService->decomposeATag($aTagValue);

            if ($decomposed === null) {
                continue;
            }

            $canonicalCoord = $this->identityService->canonicalizeATag($aTagValue);
            $isStructural = in_array($eventKind, self::STRUCTURAL_SOURCE_KINDS, true);
            $isResolvable = in_array($decomposed['kind'], self::RESOLVABLE_TARGET_KINDS, true);

            // Determine relation type
            $relation = $this->classifyRelation($eventKind, $decomposed['kind']);

            // NIP-01 "a" tag format: ["a", "kind:pubkey:d_tag", "<relay_hint>", "<marker>"]
            // Index 2 is always the relay hint (skip it), index 3 is the optional marker.
            $marker = isset($tag[3]) && $tag[3] !== '' ? $tag[3] : null;

            $refs[] = new ParsedReferenceDto(
                sourceEventId: $eventId,
                tagName: 'a',
                targetRefType: 'coordinate',
                targetKind: $decomposed['kind'],
                targetPubkey: $decomposed['pubkey'],
                targetDTag: $decomposed['d_tag'],
                targetCoord: $canonicalCoord,
                relation: $relation,
                marker: $marker,
                position: $sourceTagPosition,
                isStructural: $isStructural,
                isResolvable: $isResolvable,
            );
        }

        return $refs;
    }

    /**
     * Classify the relation type between source and target kinds.
     */
    private function classifyRelation(int $sourceKind, int $targetKind): string
    {
        // Magazine/category containing children
        if ($sourceKind === KindsEnum::PUBLICATION_INDEX->value) {
            return match ($targetKind) {
                KindsEnum::PUBLICATION_INDEX->value => 'contains',   // category
                KindsEnum::PUBLICATION_CONTENT->value => 'contains', // chapter
                KindsEnum::LONGFORM->value,
                KindsEnum::LONGFORM_DRAFT->value,
                KindsEnum::CUSTOM_NIP->value,
                KindsEnum::WIKI->value => 'contains',
                default => 'references',
            };
        }

        if ($sourceKind === KindsEnum::DIRECTORY->value) {
            return 'contains';
        }

        // Curation sets containing articles
        if (in_array($sourceKind, [
            KindsEnum::CURATION_SET->value,
            KindsEnum::CURATION_VIDEOS->value,
            KindsEnum::CURATION_PICTURES->value,
            KindsEnum::BOOKMARK_SETS->value,
        ], true)) {
            return 'contains';
        }

        return 'references';
    }

    private function isHex32(string $value): bool
    {
        return strlen($value) === 64 && ctype_xdigit($value);
    }
}
