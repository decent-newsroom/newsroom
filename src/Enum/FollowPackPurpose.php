<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Enum for the purpose of a follow pack source.
 * Each value maps a follow pack to a specific tab on the home feed.
 */
enum FollowPackPurpose: string
{
    case PODCASTS = 'podcasts';
    case NEWS_BOTS = 'news_bots';

    public function label(): string
    {
        return match ($this) {
            self::PODCASTS => 'Podcasts',
            self::NEWS_BOTS => 'News Bots',
        };
    }
}

