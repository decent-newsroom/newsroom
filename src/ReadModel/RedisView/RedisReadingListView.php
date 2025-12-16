<?php

namespace App\ReadModel\RedisView;

/**
 * Redis view model for a reading list, containing child RedisBaseObject articles
 */
final class RedisReadingListView
{
    public function __construct(
        public string $title,
        public ?string $summary,
        /** @var RedisBaseObject[] $articles */
        public array $articles
    ) {}
}

