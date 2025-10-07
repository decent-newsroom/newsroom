<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Service for matching RSS item categories/tags to nzine categories
 */
class TagMatchingService
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Find the first matching nzine category for an RSS item
     *
     * @param array $rssItemCategories Array of category strings from the RSS item
     * @param array $nzineCategories Array of nzine categories (each has 'name', 'slug', 'tags')
     * @return array|null The matched category or null if no match found
     */
    public function findMatchingCategory(array $rssItemCategories, array $nzineCategories): ?array
    {
        // Normalize RSS item categories to lowercase for case-insensitive matching
        $normalizedRssCategories = array_map('strtolower', array_map('trim', $rssItemCategories));

        foreach ($nzineCategories as $nzineCategory) {
            if (!isset($nzineCategory['tags']) || empty($nzineCategory['tags'])) {
                continue;
            }

            // Parse tags - can be array or comma-separated string
            $tags = is_array($nzineCategory['tags'])
                ? $nzineCategory['tags']
                : explode(',', $nzineCategory['tags']);

            // Normalize tags to lowercase
            $normalizedTags = array_map('strtolower', array_map('trim', $tags));

            // Check if any RSS category matches any nzine tag
            foreach ($normalizedRssCategories as $rssCategory) {
                if (in_array($rssCategory, $normalizedTags, true)) {
                    $this->logger->debug('Category match found', [
                        'rss_category' => $rssCategory,
                        'nzine_category' => $nzineCategory['name'] ?? $nzineCategory['title'] ?? $nzineCategory['slug'] ?? 'unknown',
                    ]);
                    return $nzineCategory;
                }
            }
        }

        $this->logger->debug('No category match found', [
            'rss_categories' => $rssItemCategories,
        ]);

        return null;
    }

    /**
     * Extract all unique tags from nzine categories
     *
     * @param array $nzineCategories Array of nzine categories
     * @return array Array of all unique tags
     */
    public function extractAllTags(array $nzineCategories): array
    {
        $allTags = [];

        foreach ($nzineCategories as $category) {
            if (!isset($category['tags']) || empty($category['tags'])) {
                continue;
            }

            $tags = is_array($category['tags'])
                ? $category['tags']
                : explode(',', $category['tags']);

            $allTags = array_merge($allTags, array_map('trim', $tags));
        }

        return array_unique($allTags);
    }
}
