<?php

declare(strict_types=1);

namespace App\Util;

/**
 * NIP-92 compliant imeta tag parser.
 *
 * Parses variadic imeta tag entries into a multimap.
 * Repeated keys (fallback, image) become arrays.
 * Unknown keys are preserved for forward compatibility.
 *
 * @see §10 of multimedia-manager spec
 */
final class ImetaParser
{
    /**
     * Parse a single imeta tag array into a structured multimap.
     *
     * @param array $tag e.g. ['imeta', 'url https://...', 'm image/png', 'dim 1600x900', ...]
     * @return array|null Parsed multimap or null if invalid
     */
    public static function parse(array $tag): ?array
    {
        if (empty($tag) || $tag[0] !== 'imeta') {
            return null;
        }

        $result = [];

        for ($i = 1, $count = count($tag); $i < $count; $i++) {
            $entry = $tag[$i];
            if (!is_string($entry)) {
                continue;
            }

            // Split on first space: "key value"
            $spacePos = strpos($entry, ' ');
            if ($spacePos === false) {
                // Key with no value — preserve as empty string
                $key = $entry;
                $value = '';
            } else {
                $key = substr($entry, 0, $spacePos);
                $value = substr($entry, $spacePos + 1);
            }

            // Keys that can repeat: store as arrays
            if (in_array($key, ['fallback', 'image'], true)) {
                $result[$key] = $result[$key] ?? [];
                $result[$key][] = $value;
            } elseif (isset($result[$key])) {
                // If a normally-single key appears again, promote to array
                if (!is_array($result[$key])) {
                    $result[$key] = [$result[$key]];
                }
                $result[$key][] = $value;
            } else {
                $result[$key] = $value;
            }
        }

        // Reject imeta tags with no url
        if (!isset($result['url']) || (is_string($result['url']) && $result['url'] === '')) {
            return null;
        }

        return $result;
    }

    /**
     * Parse all imeta tags from an event's tags array.
     *
     * @param array $tags Full event tags array (array of arrays)
     * @return array[] Array of parsed imeta multimaps
     */
    public static function parseAll(array $tags): array
    {
        $results = [];
        foreach ($tags as $tag) {
            if (is_array($tag) && ($tag[0] ?? '') === 'imeta') {
                $parsed = self::parse($tag);
                if ($parsed !== null) {
                    $results[] = $parsed;
                }
            }
        }
        return $results;
    }

    /**
     * Extract a single string value from a parsed imeta map.
     */
    public static function getString(array $parsed, string $key): ?string
    {
        if (!isset($parsed[$key])) {
            return null;
        }
        $val = $parsed[$key];
        return is_array($val) ? ($val[0] ?? null) : $val;
    }

    /**
     * Extract an array value from a parsed imeta map (for repeated keys).
     *
     * @return string[]
     */
    public static function getArray(array $parsed, string $key): array
    {
        if (!isset($parsed[$key])) {
            return [];
        }
        $val = $parsed[$key];
        return is_array($val) ? $val : [$val];
    }

    /**
     * Extract a float value from a parsed imeta map.
     */
    public static function getFloat(array $parsed, string $key): ?float
    {
        $str = self::getString($parsed, $key);
        return $str !== null ? (float) $str : null;
    }

    /**
     * Extract an int value from a parsed imeta map.
     */
    public static function getInt(array $parsed, string $key): ?int
    {
        $str = self::getString($parsed, $key);
        return $str !== null ? (int) $str : null;
    }
}

