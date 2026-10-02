<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Admin;

use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;

final class CategoryIndexMutation
{
    /** @return list<array{coordinate: string, relayHint: ?string, title?: ?string}> */
    public static function categories(NostrEvent $root): array
    {
        $categories = [];
        foreach ($root->tags as $tag) {
            if (($tag[0] ?? null) !== 'a' || !is_string($tag[1] ?? null) || !str_starts_with($tag[1], '30040:')) {
                continue;
            }
            $categories[] = ['coordinate' => $tag[1], 'relayHint' => is_string($tag[2] ?? null) ? $tag[2] : null];
        }

        return $categories;
    }

    /** @param list<string> $relayHints @return list<list<string>> */
    public static function add(NostrEvent $root, string $coordinate, array $relayHints): array
    {
        foreach (self::categories($root) as $category) {
            if ($category['coordinate'] === $coordinate) {
                throw new \InvalidArgumentException('unfold_admin.category_exists');
            }
        }
        if ($coordinate === sprintf('30040:%s:%s', strtolower($root->pubkey), self::dTag($root) ?? '')) {
            throw new \InvalidArgumentException('unfold_admin.invalid_category');
        }
        $tag = ['a', $coordinate];
        if ($relayHints !== []) {
            $tag[] = $relayHints[0];
        }

        return [...$root->tags, $tag];
    }

    /** @return list<list<string>> */
    public static function remove(NostrEvent $root, string $coordinate): array
    {
        $found = false;
        $tags = [];
        foreach ($root->tags as $tag) {
            if (($tag[0] ?? null) === 'a' && ($tag[1] ?? null) === $coordinate && str_starts_with($coordinate, '30040:')) {
                $found = true;
                continue;
            }
            $tags[] = $tag;
        }
        if (!$found) {
            throw new \InvalidArgumentException('unfold_admin.category_missing');
        }

        return $tags;
    }

    private static function dTag(NostrEvent $event): ?string
    {
        foreach ($event->tags as $tag) {
            if (($tag[0] ?? null) === 'd' && is_string($tag[1] ?? null)) {
                return $tag[1];
            }
        }
        return null;
    }
}
