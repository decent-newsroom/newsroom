<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Named groups of related Nostr event kinds that should be fetched together.
 *
 * These bundles reduce relay round-trips by combining related kinds into
 * single REQ messages. Each bundle targets a specific use case:
 *
 * - USER_CONTEXT: Everything needed to understand a user's identity, social graph, and preferences
 * - ARTICLE_SOCIAL: All social interactions referencing a specific article coordinate
 * - AUTHOR_CONTENT: All publishable content types for an author's profile page
 *
 * @see documentation/Nostr/fetch-optimization-plan.md
 */
final class KindBundles
{
    /**
     * User identity, social graph, and preferences.
     * All are replaceable events (only latest matters per kind+pubkey).
     */
    public const USER_CONTEXT = [
        KindsEnum::METADATA->value,            // 0   — NIP-01
        KindsEnum::FOLLOWS->value,             // 3   — NIP-02
        KindsEnum::DELETION_REQUEST->value,    // 5   — NIP-09
        KindsEnum::MUTE_LIST->value,           // 10000 — NIP-51
        KindsEnum::PIN_LIST->value,            // 10001 — NIP-51
        KindsEnum::RELAY_LIST->value,          // 10002 — NIP-65
        KindsEnum::BOOKMARKS->value,           // 10003 — NIP-51
        KindsEnum::INTERESTS->value,           // 10015 — NIP-51
        KindsEnum::MEDIA_FOLLOWS->value,       // 10020 — NIP-68
        KindsEnum::BLOSSOM_SERVER_LIST->value,  // 10063 — NIP-B7
        KindsEnum::INTEREST_SETS->value,       // 30015 — NIP-51
    ];

    /**
     * All social interactions referencing a specific article coordinate.
     * Used to fetch comments, reactions, labels, zaps, and highlights in one REQ.
     */
    public const ARTICLE_SOCIAL = [
        KindsEnum::REACTION->value,       // 7    — NIP-25
        KindsEnum::COMMENTS->value,       // 1111 — NIP-22
        KindsEnum::LABEL->value,          // 1985 — NIP-32
        KindsEnum::ZAP_REQUEST->value,    // 9734 — NIP-57
        KindsEnum::ZAP_RECEIPT->value,    // 9735 — NIP-57
        KindsEnum::HIGHLIGHTS->value,     // 9802 — NIP-84
    ];

    /**
     * All publishable content types for an author's profile page.
     * Replaces the per-content-type loop in FetchAuthorContentHandler.
     */
    public const AUTHOR_CONTENT = [
        KindsEnum::IMAGE->value,              // 20    — NIP-68
        KindsEnum::VIDEO->value,              // 21    — NIP-71
        KindsEnum::SHORT_VIDEO->value,        // 22    — NIP-71
        KindsEnum::COMMENTS->value,           // 1111  — NIP-22
        KindsEnum::HIGHLIGHTS->value,         // 9802
        KindsEnum::BOOKMARKS->value,          // 10003 — NIP-51
        KindsEnum::INTERESTS->value,          // 10015 — NIP-51
        KindsEnum::BOOKMARK_SETS->value,      // 30003 — NIP-51
        KindsEnum::CURATION_SET->value,       // 30004 — NIP-51
        KindsEnum::CURATION_VIDEOS->value,    // 30005 — NIP-51
        KindsEnum::CURATION_PICTURES->value,  // 30006 — NIP-51
        KindsEnum::INTEREST_SETS->value,      // 30015 — NIP-51
        KindsEnum::LONGFORM->value,           // 30023 — NIP-23
        KindsEnum::LONGFORM_DRAFT->value,     // 30024 — NIP-23
        KindsEnum::PLAYLIST->value,           // 34139
    ];

    /**
     * Group events by kind from a flat list of event objects.
     *
     * @param object[] $events Array of event stdClass objects with a `kind` property
     * @return array<int, object[]> kind => events, each sorted newest-first
     */
    public static function groupByKind(array $events): array
    {
        $grouped = [];
        foreach ($events as $event) {
            $kind = (int) ($event->kind ?? 0);
            $grouped[$kind][] = $event;
        }

        // Sort each group newest-first
        foreach ($grouped as $kind => &$kindEvents) {
            usort($kindEvents, fn($a, $b) => ($b->created_at ?? 0) <=> ($a->created_at ?? 0));
        }

        return $grouped;
    }

    /**
     * Get the latest event per kind from a flat list of event objects.
     * Useful for replaceable events where only the newest matters.
     *
     * @param object[] $events Array of event stdClass objects
     * @return array<int, object> kind => latest event
     */
    public static function latestByKind(array $events): array
    {
        $grouped = self::groupByKind($events);
        return array_map(fn(array $kindEvents) => $kindEvents[0], $grouped);
    }

    /**
     * Categorize article social events into named buckets.
     *
     * @param object[] $events Flat array of social events
     * @return array{reactions: object[], comments: object[], labels: object[], zap_requests: object[], zaps: object[], highlights: object[]}
     */
    public static function categorizeArticleSocial(array $events): array
    {
        $result = [
            'reactions'    => [],
            'comments'     => [],
            'labels'       => [],
            'zap_requests' => [],
            'zaps'         => [],
            'highlights'   => [],
        ];

        foreach ($events as $event) {
            $kind = (int) ($event->kind ?? 0);
            match ($kind) {
                KindsEnum::REACTION->value      => $result['reactions'][] = $event,
                KindsEnum::COMMENTS->value      => $result['comments'][] = $event,
                KindsEnum::LABEL->value         => $result['labels'][] = $event,
                KindsEnum::ZAP_REQUEST->value   => $result['zap_requests'][] = $event,
                KindsEnum::ZAP_RECEIPT->value   => $result['zaps'][] = $event,
                KindsEnum::HIGHLIGHTS->value    => $result['highlights'][] = $event,
                default => null,
            };
        }

        return $result;
    }

    /** Prevent instantiation. */
    private function __construct() {}
}

