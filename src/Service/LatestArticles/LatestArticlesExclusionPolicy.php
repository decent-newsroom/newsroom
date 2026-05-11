<?php

declare(strict_types=1);

namespace App\Service\LatestArticles;

use App\Dto\UserMetadata;
use App\Entity\Article;
use App\Service\MutedPubkeysService;

/**
 * Central policy for excluding bot-type authors and obvious promotional spam
 * from "latest articles" feeds.
 *
 * Contract:
 * - Input: Article entity or cached payload, and optionally already-fetched
 *   author metadata.
 * - Output: true if the article should be excluded from latest feeds.
 *
 * The policy merges two exclusion sources into one list so that callers
 * can push the exclusion into the initial DB/relay query instead of
 * filtering in PHP after the fetch:
 *   1. Config-level deny-list (`$excludedPubkeys` parameter)
 *   2. Admin-muted users via `MutedPubkeysService`
 *
 * It also applies a small content heuristic to skip recurring referral-code
 * spam that rotates pubkeys but keeps the same promotional structure.
 */
class LatestArticlesExclusionPolicy
{
    private const PROMOTIONAL_SPAM_PHRASES = [
        'referral code',
    ];

    private const EXCLUDED_TITLE_PREFIXES = [
        'EA://INTEL',
        'EA://NEWS',
    ];

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
        return $this->shouldExcludeByValues(
            $article->getPubkey(),
            $article->getTitle(),
            $article->getSummary(),
            $article->getContent(),
            $authorMetadata,
        );
    }

    /**
     * Apply the same latest-feed exclusion logic to cached article payloads.
     *
     * @param array|\stdClass $articleData Decoded article payload from Redis
     */
    public function shouldExcludeArticleData(array|\stdClass $articleData, array|\stdClass|UserMetadata|null $authorMetadata = null): bool
    {
        return $this->shouldExcludeByValues(
            $this->extractString($articleData, 'pubkey'),
            $this->extractString($articleData, 'title'),
            $this->extractString($articleData, 'summary'),
            $this->extractString($articleData, 'content'),
            $authorMetadata,
        );
    }

    private function shouldExcludeByValues(
        ?string $pubkey,
        ?string $title,
        ?string $summary,
        ?string $content,
        array|\stdClass|UserMetadata|null $authorMetadata = null,
    ): bool {
        if ($pubkey && in_array($pubkey, $this->excludedPubkeys, true)) {
            return true;
        }

        if ($this->excludeBotProfiles) {
            $meta = $this->normalizeMetadata($authorMetadata);
            if ($meta?->bot === true) {
                return true;
            }
        }

        if ($this->hasBotTitlePrefix($title)) {
            return true;
        }

        return $this->containsPromotionalSpam($title, $summary, $content);
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

    private function hasBotTitlePrefix(?string $title): bool
    {
        if (null === $title || '' === $title) {
            return false;
        }

        foreach (self::EXCLUDED_TITLE_PREFIXES as $prefix) {
            if (str_starts_with($title, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function containsPromotionalSpam(?string $title, ?string $summary, ?string $content): bool
    {
        foreach ([$title, $summary, $content] as $field) {
            if (null === $field || '' === trim($field)) {
                continue;
            }

            $normalizedField = strtolower($field);
            foreach (self::PROMOTIONAL_SPAM_PHRASES as $phrase) {
                if (str_contains($normalizedField, $phrase)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function extractString(array|\stdClass $data, string $property): ?string
    {
        if (is_array($data)) {
            $value = $data[$property] ?? null;
        } else {
            if (!isset($data->$property)) {
                return null;
            }

            $value = $data->$property;
        }

        if (is_array($value)) {
            return !empty($value) ? (string) $value[0] : null;
        }

        if (is_string($value) && '' !== $value) {
            return $value;
        }

        return null;
    }
}
