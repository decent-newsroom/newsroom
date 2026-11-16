<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

/**
 * Kind-aware scope guards for workspace targets and filesystem sources.
 * Generic targets fail closed on malformed addresses; drive and directory
 * source relationships are interpreted according to their source kind.
 */
final class EventWorkspaceScopePolicy
{
    /**
     * Inventory list references remain children; only legacy community
     * addresses are scopes. Publication indexes and chapters additionally
     * use `s` for audience scopes. Malformed scope markers must not become public.
     *
     * @param array<array-key, mixed> $tags
     */
    public static function isScopedInventoryList(int $kind, array $tags): bool
    {
        if (self::hasScopeMarkers($tags) || self::hasPublicationAudienceScope($kind, $tags)) {
            return true;
        }

        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            if (($tag[0] ?? null) === 'a' && is_string($tag[1] ?? null)) {
                $kindPart = explode(':', $tag[1], 2)[0];
                if ($kindPart !== '' && ctype_digit($kindPart) && (int) $kindPart === 34550) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Generic target checks retain their conservative address handling while
     * interpreting audience markers only for their publication kinds.
     *
     * @param array<array-key, mixed> $tags
     */
    public static function isScopedTarget(int $kind, array $tags): bool
    {
        return self::hasPublicationAudienceScope($kind, $tags) || self::isScoped($tags);
    }

    /** @param array<array-key, mixed> $tags */
    private static function hasPublicationAudienceScope(int $kind, array $tags): bool
    {
        if (!in_array($kind, [30040, 30041], true)) {
            return false;
        }
        foreach ($tags as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 's') {
                return true;
            }
        }

        return false;
    }

    /**
     * A drive may itself be community-scoped. Other `a` tags are drive
     * relationships and remain occurrences, including malformed ones.
     *
     * @param array<array-key, mixed> $tags
     */
    public static function isScopedDriveRoot(array $tags): bool
    {
        if (self::hasScopeMarkers($tags)) {
            return true;
        }

        foreach ($tags as $tag) {
            if (!is_array($tag) || ($tag[0] ?? null) !== 'a' || !is_string($tag[1] ?? null)) {
                continue;
            }
            if (self::addressKind($tag[1]) === 34550) {
                return true;
            }
        }

        return false;
    }

    /**
     * A directory's `a` tags are children, not scope markers. Scope markers
     * attached to the directory itself still deny target metadata.
     *
     * @param array<array-key, mixed> $tags
     */
    public static function isScopedDirectory(array $tags): bool
    {
        return self::hasScopeMarkers($tags);
    }

    /**
     * @param array<array-key, mixed> $tags
     */
    public static function isScoped(array $tags): bool
    {
        if (self::hasScopeMarkers($tags)) {
            return true;
        }

        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            if (in_array($tag[0] ?? null, ['h', 'A'], true)) {
                return true;
            }
            if (($tag[0] ?? null) !== 'a') {
                continue;
            }

            $coordinate = $tag[1] ?? null;
            if (!is_string($coordinate)) {
                // A target with malformed address tags is unsupported. Source
                // occurrence rendering uses hasScopeMarkers() instead, so the
                // original malformed row remains visible and unresolved.
                return true;
            }
            $kind = self::addressKind($coordinate);
            if ($kind === null) {
                return true;
            }
            if ($kind === 34550) {
                return true;
            }
        }

        return false;
    }

    /**
     * Directory and drive relationship tags may point at scoped content
     * without making the containing filesystem node itself scoped.
     *
     * @param array<array-key, mixed> $tags
     */
    public static function hasScopeMarkers(array $tags): bool
    {
        foreach ($tags as $tag) {
            if (is_array($tag) && in_array($tag[0] ?? null, ['h', 'A'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function addressKind(string $coordinate): ?int
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3
            || !ctype_digit($parts[0])
            || preg_match('/^[0-9a-f]{64}$/iD', $parts[1]) !== 1) {
            return null;
        }

        return (int) $parts[0];
    }
}
