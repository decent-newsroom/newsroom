<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Admin;

use DecentNewsroom\UnfoldBundle\Config\AboutArticleReference;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;

/** Applies an About change to a root index without rewriting any other tags. */
final class AboutIndexMutation
{
    public static function currentCoordinate(NostrEvent $root, ?string $storedCoordinate): ?string
    {
        if ($storedCoordinate !== null) {
            return $storedCoordinate;
        }

        $references = self::directArticles($root->tags);

        return count($references) === 1 ? $references[0] : null;
    }

    /**
     * @return list<list<string>>
     */
    public static function replace(NostrEvent $root, ?string $storedCoordinate, ?string $selectedCoordinate): array
    {
        $references = self::directArticles($root->tags);
        $inferred = count($references) === 1 ? $references[0] : null;
        $remove = [];
        if ($storedCoordinate !== null) {
            $remove[] = $storedCoordinate;
        }
        // A sole direct root article is conventional About. Remove it as well
        // when the saved selection was off-index, or it would become the fallback.
        if ($inferred !== null) {
            $remove[] = $inferred;
        }

        $tags = [];
        foreach ($root->tags as $tag) {
            $coordinate = ($tag[0] ?? null) === 'a' ? self::articleCoordinate($tag[1] ?? '') : null;
            if ($coordinate !== null && $coordinate !== $selectedCoordinate && in_array($coordinate, $remove, true)) {
                continue;
            }
            $tags[] = $tag;
        }

        if ($selectedCoordinate !== null && !in_array($selectedCoordinate, self::directArticles($tags), true)) {
            $tags[] = ['a', $selectedCoordinate];
        }

        return $tags;
    }

    /**
     * @param list<list<string>> $tags
     * @return list<string>
     */
    private static function directArticles(array $tags): array
    {
        $coordinates = [];
        foreach ($tags as $tag) {
            if (($tag[0] ?? null) !== 'a') {
                continue;
            }
            $coordinate = self::articleCoordinate($tag[1] ?? '');
            if ($coordinate !== null && !in_array($coordinate, $coordinates, true)) {
                $coordinates[] = $coordinate;
            }
        }

        return $coordinates;
    }

    private static function articleCoordinate(string $input): ?string
    {
        if (!str_starts_with($input, '30023:')) {
            return null;
        }
        try {
            return AboutArticleReference::fromInput($input)->coordinate;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}