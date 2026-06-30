<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use App\Entity\Article;
use App\Entity\User;
use App\Util\NostrKeyUtil;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Secondary article actions kept behind the social strip's overflow button.
 */
#[AsTwigComponent('Molecules:ArticleActionsDropdown')]
final class ArticleActionsDropdown
{
    public function __construct(private readonly Security $security)
    {
    }

    public Article $article;

    /** Nostr coordinate, e.g. "30023:<pubkey>:<slug>" */
    public string $coordinate = '';

    /** Canonical URL of the article. */
    public string $canonicalUrl = '';

    /** naddr-encoded identifier for the article. */
    public string $naddrEncoded = '';

    /** Whether the article is protected (has '-' tag). */
    public bool $isProtected = false;

    /** Number of highlights. */
    public int $highlightCount = 0;

    /** User's write relays (null if not logged in). */
    public ?array $relays = null;

    /**
     * True when the current viewer is allowed to flip the
     * Essayist-exclusive flag on this article — i.e. they are the
     * article's author or hold ROLE_ADMIN.
     */
    public function canToggleEssayistExclusive(): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return false;
        }
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }
        $pubkey = $this->article->getPubkey();
        if ($pubkey === null) {
            return false;
        }
        try {
            return hash_equals($pubkey, NostrKeyUtil::npubToHex($user->getUserIdentifier()));
        } catch (\Throwable) {
            return false;
        }
    }

    public function isEssayistExclusive(): bool
    {
        return $this->article->isEssayistExclusive();
    }

    /**
     * True when the current viewer is the author of this article.
     * Used to enforce NIP-70: only the author may re-broadcast a protected event.
     */
    public function isAuthorOfArticle(): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return false;
        }
        $pubkey = $this->article->getPubkey();
        if ($pubkey === null) {
            return false;
        }
        try {
            return hash_equals($pubkey, NostrKeyUtil::npubToHex($user->getUserIdentifier()));
        } catch (\Throwable) {
            return false;
        }
    }
}
