<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use App\Entity\Article;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Consolidated article actions dropdown.
 *
 * Groups secondary article actions (share, bookmark, broadcast, highlights)
 * into a single dropdown, keeping Zap and Reading List as standalone buttons.
 */
#[AsTwigComponent]
final class ArticleActionsDropdown
{
    public Article $article;

    /** Nostr coordinate, e.g. "30023:<pubkey>:<slug>" */
    public string $coordinate = '';

    /** Canonical URL of the article */
    public string $canonicalUrl = '';

    /** naddr-encoded identifier for the article */
    public string $naddrEncoded = '';

    /** Whether the article is protected (has '-' tag) */
    public bool $isProtected = false;

    /** Number of highlights */
    public int $highlightCount = 0;

    /** User's write relays (null if not logged in) */
    public ?array $relays = null;
}

