<?php

declare(strict_types=1);

namespace App\Service\Graph;

use App\Entity\Event;
use App\Enum\KindsEnum;

/**
 * Extracts DN-relevant references from event tags.
 *
 * Phase 1 scope: only `a` tags (coordinate references).
 * Returns normalized reference DTOs ready for insertion into parsed_reference.
 */
class ReferenceParserService
{
    /** Kinds whose `a` tags represent structural containment (parent→child). */
    private const STRUCTURAL_SOURCE_KINDS = [
        30040, // PUBLICATION_INDEX — magazines, categories, reading lists
        30004, // CURATION_SET
        30005, // CURATION_VIDEOS
        30006, // CURATION_PICTURES
        30003, // BOOKMARK_SETS
    ];

    /** Kinds that the graph should track as resolvable targets. */
    private const RESOLVABLE_TARGET_KINDS = [
        30040, // PUBLICATION_INDEX
        30041, // PUBLICATION_CONTENT (chapters)
        30023, // LONGFORM (articles)
        30024, // LONGFORM_DRAFT
        30004, // CURATION_SET
        30005, // CURATION_VIDEOS
        30006, // CURATION_PICTURES
        30003, // BOOKMARK_SETS
        30078, // APP_DATA
    ];

    public function __construct(
        private readonly RecordIdentityService $identityService,
    ) {}

    /**
     * Parse all `a` tag references from an Event entity.
     *
     * @return ParsedReferenceDto[]
     */
    public function parseReferences(Event $event): array
    {
        return $this->parseFromTagsArray($event->getId(), $event->getKind(), $event->getTags());
    }

    /**
     * Parse all `a` tag references from raw event data.
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
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }

            if ($tag[0] !== 'a') {
                continue;
            }

            $aTagValue = $tag[1];
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
                position: $position,
                isStructural: $isStructural,
                isResolvable: $isResolvable,
            );

            $position++;
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
                KindsEnum::LONGFORM_DRAFT->value => 'contains',     // article
                default => 'references',
            };
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
}

