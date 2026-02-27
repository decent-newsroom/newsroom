<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched when an article's highlights cache is stale.
 * The handler will fetch fresh highlights from Nostr relays in the background,
 * keeping the web request fast and avoiding proxy timeouts.
 */
class RefreshArticleHighlightsMessage
{
    public function __construct(
        private readonly string $articleCoordinate,
        private readonly bool $fullRefresh = false,
    ) {}

    public function getArticleCoordinate(): string
    {
        return $this->articleCoordinate;
    }

    public function isFullRefresh(): bool
    {
        return $this->fullRefresh;
    }
}

