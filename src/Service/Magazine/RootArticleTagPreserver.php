<?php

declare(strict_types=1);

namespace App\Service\Magazine;

/** Keeps direct long-form root references when the wizard rebuilds metadata. */
final class RootArticleTagPreserver
{
    /**
     * @param list<list<string>> $rebuiltTags
     * @param list<list<string>> $previousTags
     * @return list<list<string>>
     */
    public static function append(array $rebuiltTags, array $previousTags): array
    {
        foreach ($previousTags as $tag) {
            if (($tag[0] ?? null) !== 'a' || !is_string($tag[1] ?? null)
                || preg_match('/^30023:[a-fA-F0-9]{64}:.+$/Ds', $tag[1]) !== 1) {
                continue;
            }
            if (!in_array($tag, $rebuiltTags, true)) {
                $rebuiltTags[] = $tag;
            }
        }

        return $rebuiltTags;
    }
}