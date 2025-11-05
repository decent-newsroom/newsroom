<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Dto\AdvancedMetadata;
use App\Dto\ZapSplit;
use swentel\nostr\Key\Key;

class NostrEventParser
{
    private Key $key;

    public function __construct()
    {
        $this->key = new Key();
    }

    /**
     * Parse tags from a Nostr event into AdvancedMetadata DTO
     *
     * @param array $tags The tags array from the Nostr event
     * @return AdvancedMetadata
     */
    public function parseAdvancedMetadata(array $tags): AdvancedMetadata
    {
        $metadata = new AdvancedMetadata();
        $knownTags = ['d', 'title', 'summary', 'image', 'published_at', 't', 'client'];
        $processedAdvancedTags = [];

        foreach ($tags as $tag) {
            if (!is_array($tag) || empty($tag)) {
                continue;
            }

            $tagName = $tag[0] ?? '';

            switch ($tagName) {
                case 'L':
                    // Label namespace - just track it, process with 'l' tags
                    break;

                case 'l':
                    $this->parseLabel($tag, $metadata);
                    $processedAdvancedTags[] = $tag;
                    break;

                case 'zap':
                    $split = $this->parseZapTag($tag);
                    if ($split) {
                        $metadata->addZapSplit($split);
                        $processedAdvancedTags[] = $tag;
                    }
                    break;

                case 'content-warning':
                    $metadata->contentWarning = $tag[1] ?? '';
                    $processedAdvancedTags[] = $tag;
                    break;

                case 'expiration':
                    $timestamp = (int)($tag[1] ?? 0);
                    if ($timestamp > 0) {
                        $metadata->expirationTimestamp = $timestamp;
                    }
                    $processedAdvancedTags[] = $tag;
                    break;

                case '-':
                    $metadata->isProtected = true;
                    $processedAdvancedTags[] = $tag;
                    break;

                default:
                    // Preserve unknown tags for passthrough
                    if (!in_array($tagName, $knownTags, true)) {
                        $metadata->extraTags[] = $tag;
                    }
                    break;
            }
        }

        return $metadata;
    }

    /**
     * Parse a label tag (NIP-32)
     */
    private function parseLabel(array $tag, AdvancedMetadata $metadata): void
    {
        $label = $tag[1] ?? '';
        $namespace = $tag[2] ?? '';

        if ($namespace === 'rights.decent.newsroom') {
            if ($label === 'no-republish') {
                $metadata->doNotRepublish = true;
            } elseif ($label === 'all-rights-reserved') {
                $metadata->license = 'All rights reserved';
            }
        } elseif ($namespace === 'spdx.org/licenses') {
            $metadata->license = $label;
        }
    }

    /**
     * Parse a zap split tag
     *
     * Format: ["zap", <hex-pubkey>, <relay>, <weight>]
     * Relay and weight are optional
     */
    private function parseZapTag(array $tag): ?ZapSplit
    {
        if (count($tag) < 2) {
            return null;
        }

        $pubkeyHex = $tag[1] ?? '';
        if (empty($pubkeyHex)) {
            return null;
        }

        // Convert hex to npub for display (more user-friendly)
        try {
            $npub = $this->key->convertPublicKeyToBech32($pubkeyHex);
        } catch (\Exception $e) {
            // If conversion fails, use hex
            $npub = $pubkeyHex;
        }

        $relay = $tag[2] ?? null;
        if ($relay === '') {
            $relay = null;
        }

        $weight = isset($tag[3]) && $tag[3] !== '' ? (int)$tag[3] : null;

        return new ZapSplit($npub, $relay, $weight);
    }

    /**
     * Extract basic article data from tags
     */
    public function extractArticleData(array $tags): array
    {
        $data = [
            'slug' => '',
            'title' => '',
            'summary' => '',
            'image' => '',
            'topics' => [],
            'publishedAt' => null,
        ];

        foreach ($tags as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }

            switch ($tag[0]) {
                case 'd':
                    $data['slug'] = $tag[1];
                    break;
                case 'title':
                    $data['title'] = $tag[1];
                    break;
                case 'summary':
                    $data['summary'] = $tag[1];
                    break;
                case 'image':
                    $data['image'] = $tag[1];
                    break;
                case 't':
                    $data['topics'][] = $tag[1];
                    break;
                case 'published_at':
                    $timestamp = (int)$tag[1];
                    if ($timestamp > 0) {
                        $data['publishedAt'] = new \DateTimeImmutable('@' . $timestamp);
                    }
                    break;
            }
        }

        return $data;
    }
}

