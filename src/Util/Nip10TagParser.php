<?php

declare(strict_types=1);

namespace App\Util;

/**
 * NIP-10 tag parser helpers for kind-1 threading metadata.
 */
final class Nip10TagParser
{
    /**
     * @param array<int, mixed> $tags
     *
     * @return array{eventId: string, relays: string[]}|null
     */
    public static function findRootReference(array $tags): ?array
    {
        foreach ($tags as $tag) {
            if (!is_array($tag) || count($tag) < 2 || ($tag[0] ?? null) !== 'e') {
                continue;
            }

            $eventId = (string) ($tag[1] ?? '');
            if ($eventId === '') {
                continue;
            }

            $auxiliaries = self::parseReferenceAuxiliaries($tag);
            if ($auxiliaries['marker'] !== 'root') {
                continue;
            }

            return [
                'eventId' => $eventId,
                'relays' => $auxiliaries['relays'],
            ];
        }

        return null;
    }

    /**
     * Parse the compatibility marker and relay slots of an e tag without
     * interpreting its target or resolving either reference.
     *
     * @param array<array-key, mixed> $tag
     * @return array{marker: ?string, relays: list<string>}
     */
    public static function parseReferenceAuxiliaries(array $tag): array
    {
        $marker = null;
        $relayHints = [];
        foreach ([2, 3] as $index) {
            $value = $tag[$index] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }

            if (self::isMarker($value)) {
                $marker = strtolower($value);
                continue;
            }

            if (self::isWebSocketSchemeCandidate($value)) {
                $relayHints[] = $value;
            }
        }

        return [
            'marker' => $marker,
            'relays' => array_values(array_unique($relayHints)),
        ];
    }

    private static function isMarker(string $value): bool
    {
        $normalized = strtolower($value);

        return in_array($normalized, ['root', 'reply', 'mention'], true);
    }

    /**
     * Recognize a scheme prefix only, not a validated or safe relay URL.
     * Callers must preserve the original value and apply their own URL and
     * network policy before using it.
     */
    public static function isWebSocketSchemeCandidate(string $value): bool
    {
        return strncasecmp($value, 'ws://', 5) === 0 || strncasecmp($value, 'wss://', 6) === 0;
    }
}
