<?php

namespace App\ReadModel\RedisView;

/**
 * Redis base object - the fundamental unit stored in Redis view lists
 * Contains all data needed to render an item without additional queries
 *
 * This is the top-level structure stored in Redis as JSON for:
 * - view:articles:latest
 * - view:highlights:latest
 * - view:user:articles:<pubkey>
 */
final readonly class RedisBaseObject
{
    /**
     * @param RedisArticleView|null $article Article data (if applicable)
     * @param RedisHighlightView|null $highlight Highlight data (if applicable)
     * @param RedisProfileView|null $author Primary author (article or highlight author)
     * @param array<string, RedisProfileView> $profiles Map of all referenced profiles (pubkey => profile)
     * @param array $meta Extensible metadata (zaps, counts, etc.)
     */
    public function __construct(
        public ?RedisArticleView $article = null,
        public ?RedisHighlightView $highlight = null,
        public ?RedisProfileView $author = null,
        public array $profiles = [],
        public array $meta = [],
    ) {}
}

