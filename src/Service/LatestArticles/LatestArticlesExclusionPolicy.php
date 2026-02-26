<?php

declare(strict_types=1);

namespace App\Service\LatestArticles;

use App\Dto\UserMetadata;
use App\Entity\Article;

/**
 * Central policy for excluding bot-type authors from "latest articles" feeds.
 *
 * Contract:
 * - Input: Article entity and optionally already-fetched author metadata.
 * - Output: true if the article should be excluded from latest feeds.
 */
class LatestArticlesExclusionPolicy
{
    /**
     * @param string[] $excludedPubkeys Hex pubkeys to always exclude
     */
    public function __construct(
        private readonly array $excludedPubkeys = [],
        private readonly bool $excludeBotProfiles = true,
    ) {}

    public function shouldExclude(Article $article, array|\stdClass|UserMetadata|null $authorMetadata = null): bool
    {
        $pubkey = $article->getPubkey();
        if ($pubkey && in_array($pubkey, $this->excludedPubkeys, true)) {
            return true;
        }

        if ($this->excludeBotProfiles) {
            $meta = $this->normalizeMetadata($authorMetadata);
            if ($meta?->bot === true) {
                return true;
            }
        }

        return false;
    }

    private function normalizeMetadata(array|\stdClass|UserMetadata|null $authorMetadata): ?UserMetadata
    {
        if ($authorMetadata instanceof UserMetadata) {
            return $authorMetadata;
        }
        if ($authorMetadata instanceof \stdClass) {
            return UserMetadata::fromStdClass($authorMetadata);
        }
        if (is_array($authorMetadata)) {
            // Best-effort conversion. If it's already a decoded profile JSON shape,
            // json_encode/decode keeps behavior consistent with legacy cache.
            $std = json_decode(json_encode($authorMetadata));
            if ($std instanceof \stdClass) {
                return UserMetadata::fromStdClass($std);
            }
        }
        return null;
    }
}
