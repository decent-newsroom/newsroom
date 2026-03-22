<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Bookmark button for articles (NIP-51, kind 10003).
 *
 * Renders a button wired to the nostr--nostr-bookmark Stimulus controller
 * that lets the user add/remove an article from their bookmarks.
 */
#[AsTwigComponent]
final class BookmarkButton
{
    /**
     * The Nostr coordinate of the article to bookmark.
     * Format: "30023:<pubkey>:<d-tag>"
     */
    public string $coordinate = '';
}

