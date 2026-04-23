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

                if (self::isRelayUrl($value)) {
                    $relayHints[] = $value;
                }
            }

            if ($marker !== 'root') {
                continue;
            }

            return [
                'eventId' => $eventId,
                'relays' => array_values(array_unique($relayHints)),
            ];
        }

        return null;
    }

    private static function isMarker(string $value): bool
    {
        $normalized = strtolower($value);

        return in_array($normalized, ['root', 'reply', 'mention'], true);
    }

    private static function isRelayUrl(string $value): bool
    {
        return str_starts_with($value, 'ws://') || str_starts_with($value, 'wss://');
    }
}

