<?php

declare(strict_types=1);

namespace App\Service\LatestArticles;

use App\Dto\UserMetadata;
use App\Entity\Article;
use App\Service\MutedPubkeysService;

/**
 * Central policy for excluding bot-type authors from "latest articles" feeds.
 *
 * Contract:
 * - Input: Article entity and optionally already-fetched author metadata.
 * - Output: true if the article should be excluded from latest feeds.
 *
 * The policy merges two exclusion sources into one list so that callers
 * can push the exclusion into the initial DB/relay query instead of
 * filtering in PHP after the fetch:
 *   1. Config-level deny-list (`$excludedPubkeys` parameter)
 *   2. Admin-muted users via `MutedPubkeysService`
 */
class LatestArticlesExclusionPolicy
{
    /**
     * @param string[] $excludedPubkeys Hex pubkeys to always exclude
     */
    public function __construct(
        private readonly MutedPubkeysService $mutedPubkeysService,
        private readonly array $excludedPubkeys = [],
        private readonly bool $excludeBotProfiles = true,
    ) {}

    /**
     * Return every pubkey that should be excluded from latest feeds.
     * Merges the config-level deny-list with the admin-muted users.
     *
     * Use this in the initial DB/relay query (`NOT IN (...)`) so that
     * excluded authors never consume the row budget.
     *
     * @return string[] Unique hex pubkeys
     */
    public function getAllExcludedPubkeys(): array
    {
        return array_values(array_unique(array_merge(
            $this->excludedPubkeys,
            $this->mutedPubkeysService->getMutedPubkeys(),
        )));
    }

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
