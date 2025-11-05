<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Dto\AdvancedMetadata;
use App\Dto\ZapSplit;
use App\Entity\Article;
use nostriphant\NIP19\Bech32;
use swentel\nostr\Key\Key;

class NostrEventBuilder
{
    private Key $key;

    public function __construct()
    {
        $this->key = new Key();
    }

    /**
     * Build tags array from Article and AdvancedMetadata
     *
     * @param Article $article
     * @param AdvancedMetadata|null $metadata
     * @param array $formData Additional form data (isDraft, addClientTag, etc.)
     * @return array
     */
    public function buildTags(Article $article, ?AdvancedMetadata $metadata, array $formData = []): array
    {
        $tags = [];

        // Base NIP-23 tags
        $tags[] = ['d', $article->getSlug() ?? ''];
        $tags[] = ['title', $article->getTitle() ?? ''];
        $tags[] = ['published_at', (string)($article->getPublishedAt()?->getTimestamp() ?? time())];

        if ($article->getSummary()) {
            $tags[] = ['summary', $article->getSummary()];
        }

        if ($article->getImage()) {
            $tags[] = ['image', $article->getImage()];
        }

        // Topic tags
        if ($article->getTopics()) {
            foreach ($article->getTopics() as $topic) {
                $cleanTopic = str_replace('#', '', $topic);
                $tags[] = ['t', $cleanTopic];
            }
        }

        // Client tag
        if ($formData['addClientTag'] ?? false) {
            $tags[] = ['client', 'Decent Newsroom'];
        }

        // Advanced metadata tags
        if ($metadata) {
            $tags = array_merge($tags, $this->buildAdvancedTags($metadata));
        }

        return $tags;
    }

    /**
     * Build advanced metadata tags
     */
    private function buildAdvancedTags(AdvancedMetadata $metadata): array
    {
        $tags = [];

        // Policy: Do not republish
        if ($metadata->doNotRepublish) {
            $tags[] = ['L', 'rights.decent.newsroom'];
            $tags[] = ['l', 'no-republish', 'rights.decent.newsroom'];
        }

        // License
        $license = $metadata->getLicenseValue();
        if ($license && $license !== 'All rights reserved') {
            $tags[] = ['L', 'spdx.org/licenses'];
            $tags[] = ['l', $license, 'spdx.org/licenses'];
        } elseif ($license === 'All rights reserved') {
            $tags[] = ['L', 'rights.decent.newsroom'];
            $tags[] = ['l', 'all-rights-reserved', 'rights.decent.newsroom'];
        }

        // Zap splits
        foreach ($metadata->zapSplits as $split) {
            $zapTag = ['zap', $this->convertToHex($split->recipient)];

            if ($split->relay) {
                $zapTag[] = $split->relay;
            } else {
                $zapTag[] = '';
            }

            if ($split->weight !== null) {
                $zapTag[] = (string)$split->weight;
            }

            $tags[] = $zapTag;
        }

        // Content warning
        if ($metadata->contentWarning) {
            $tags[] = ['content-warning', $metadata->contentWarning];
        }

        // Expiration
        if ($metadata->expirationTimestamp) {
            $tags[] = ['expiration', (string)$metadata->expirationTimestamp];
        }

        // Protected event
        if ($metadata->isProtected) {
            $tags[] = ['-'];
        }

        // Extra tags (passthrough)
        foreach ($metadata->extraTags as $tag) {
            if (is_array($tag)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * Convert npub or hex to hex pubkey
     */
    public function convertToHex(string $pubkey): string
    {
        if (str_starts_with($pubkey, 'npub1')) {
            try {
                return $this->key->convertToHex($pubkey);
            } catch (\Exception $e) {
                throw new \InvalidArgumentException('Invalid npub format: ' . $e->getMessage());
            }
        }

        // Validate hex format
        if (!preg_match('/^[0-9a-f]{64}$/i', $pubkey)) {
            throw new \InvalidArgumentException('Invalid pubkey format. Must be hex (64 chars) or npub');
        }

        return strtolower($pubkey);
    }

    /**
     * Calculate share percentages for zap splits
     *
     * @param ZapSplit[] $splits
     * @return array Array of percentages indexed by split position
     */
    public function calculateShares(array $splits): array
    {
        if (empty($splits)) {
            return [];
        }

        $hasWeights = false;
        $totalWeight = 0;

        foreach ($splits as $split) {
            if ($split->weight !== null && $split->weight > 0) {
                $hasWeights = true;
                $totalWeight += $split->weight;
            }
        }

        // If no weights specified, equal distribution
        if (!$hasWeights) {
            $equalShare = 100.0 / count($splits);
            return array_fill(0, count($splits), $equalShare);
        }

        // Calculate weighted shares
        $shares = [];
        foreach ($splits as $split) {
            $weight = $split->weight ?? 0;
            $shares[] = $totalWeight > 0 ? ($weight / $totalWeight) * 100 : 0;
        }

        return $shares;
    }
}

