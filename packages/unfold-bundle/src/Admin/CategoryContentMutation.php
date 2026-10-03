<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Admin;

use DecentNewsroom\UnfoldBundle\Config\ContentReference;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;

final class CategoryContentMutation
{
    public static function assertAttached(NostrEvent $root, string $publication, string $category): void
    {
        self::assertTags($root->tags);
        if (!self::matchesCategory($root, $publication) || $category === $publication) {
            throw new \InvalidArgumentException('unfold_category.detached');
        }
        foreach ($root->tags as $tag) {
            if (($tag[0] ?? null) === 'a' && is_string($tag[1] ?? null)) {
                if (preg_match('/^30040:([a-fA-F0-9]{64}):(.+)$/Ds', $tag[1], $parts) === 1
                    && '30040:' . strtolower($parts[1]) . ':' . $parts[2] === $category) {
                    return;
                }
            }
        }
        throw new \InvalidArgumentException('unfold_category.detached');
    }

    public static function matchesCategory(NostrEvent $event, string $coordinate): bool
    {
        $identifiers = [];
        foreach ($event->tags as $tag) {
            if (($tag[0] ?? null) === 'd') {
                $identifiers[] = $tag[1] ?? null;
            }
        }
        return $event->kind === 30040 && count($identifiers) === 1 && is_string($identifiers[0])
            && '30040:' . strtolower($event->pubkey) . ':' . $identifiers[0] === $coordinate;
    }

    /** @return list<array{reference: ContentReference, relayHint: ?string}> */
    public static function references(NostrEvent $event): array
    {
        self::assertTags($event->tags);
        $references = [];
        foreach ($event->tags as $tag) {
            if (($tag[0] ?? null) !== 'a' || !is_string($tag[1] ?? null)) {
                continue;
            }
            try {
                $reference = self::signedReference($tag[1]);
                $references[$reference->coordinate] ??= [
                    'reference' => $reference,
                    'relayHint' => is_string($tag[2] ?? null) ? $tag[2] : null,
                ];
            } catch (\InvalidArgumentException) {
            }
        }
        return array_values($references);
    }

    /** @return list<list<string>> */
    public static function tags(NostrEvent $category, ContentReference $reference, string $action): array
    {
        self::assertTags($category->tags);
        if (!in_array($action, ['add', 'remove'], true)) {
            throw new \InvalidArgumentException('unfold_category.invalid');
        }
        $tags = [];
        $found = false;
        foreach ($category->tags as $tag) {
            $matches = false;
            if (($tag[0] ?? null) === 'a' && is_string($tag[1] ?? null)) {
                try {
                    $matches = self::signedReference($tag[1])->coordinate === $reference->coordinate;
                } catch (\InvalidArgumentException) {
                }
            }
            $found = $found || $matches;
            if (!$matches || $action !== 'remove') {
                $tags[] = $tag;
            }
        }
        if ($action === 'add' && !$found) {
            $tag = ['a', $reference->coordinate];
            if ($reference->relayHints !== []) {
                $tag[] = $reference->relayHints[0];
            }
            $tags[] = $tag;
        }
        return $tags;
    }

    public static function assertTags(array $tags): void
    {
        if (!array_is_list($tags)) {
            throw new \InvalidArgumentException('unfold_category.invalid');
        }
        foreach ($tags as $tag) {
            if (!is_array($tag) || !array_is_list($tag) || $tag === []) {
                throw new \InvalidArgumentException('unfold_category.invalid');
            }
            foreach ($tag as $value) {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException('unfold_category.invalid');
                }
            }
        }
    }

    private static function signedReference(string $coordinate): ContentReference
    {
        if (preg_match('/^([0-9]+):([a-fA-F0-9]{64}):(.+)$/Ds', $coordinate, $parts) !== 1) {
            throw new \InvalidArgumentException('unfold_category.invalid');
        }
        // Signed identifiers are not user input: preserve their whitespace and case.
        return ContentReference::fromEvent(new NostrEvent('', $parts[2], (int) $parts[1], '', [['d', $parts[3]]], 0, ''));
    }
}
