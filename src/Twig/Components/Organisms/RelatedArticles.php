<?php

namespace App\Twig\Components\Organisms;

use App\Service\Nostr\NostrClient;
use App\Service\Search\ArticleSearchInterface;
use App\Util\NostrKeyUtil;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Suggests related articles based on the intersection of the current
 * article's tags and the logged-in user's interests (kind 10015).
 *
 * For anonymous users, falls back to articles sharing the same tags.
 * Renders nothing when no related articles are found.
 */
#[AsTwigComponent]
final class RelatedArticles
{
    /** Current article's coordinate (e.g. "30023:pubkey:slug") */
    public string $coordinate;

    /** Current article's topics/tags */
    public array $topics = [];

    /** Current article's author pubkey (hex) */
    public string $pubkey;

    /** Resolved related articles */
    public array $articles = [];

    /** Whether suggestions come from user interests (true) or just article tags (false) */
    public bool $fromInterests = false;

    private const MAX_RESULTS = 3;

    public function __construct(
        private readonly ArticleSearchInterface $articleSearch,
        private readonly NostrClient $nostrClient,
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {}

    public function mount(string $coordinate, array $topics, string $pubkey): void
    {
        $this->coordinate = $coordinate;
        $this->topics = $topics;
        $this->pubkey = $pubkey;

        if (empty($this->topics)) {
            return;
        }

        try {
            $this->articles = $this->resolve();
        } catch (\Throwable $e) {
            $this->logger->warning('RelatedArticles: failed to resolve', [
                'coordinate' => $coordinate,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolve(): array
    {
        $user = $this->security->getUser();
        $searchTags = array_map('strtolower', $this->topics);

        // For logged-in users, intersect article tags with their interests
        if ($user) {
            try {
                $hex = NostrKeyUtil::npubToHex($user->getUserIdentifier());
                $interests = $this->nostrClient->getUserInterests($hex);

                if (!empty($interests)) {
                    $interestTags = array_map('strtolower', $interests);
                    $intersection = array_values(array_intersect($searchTags, $interestTags));

                    if (!empty($intersection)) {
                        $searchTags = $intersection;
                        $this->fromInterests = true;
                    }
                    // If no intersection, fall through to use article tags
                }
            } catch (\Throwable $e) {
                // Non-critical: fall back to article tags
                $this->logger->debug('RelatedArticles: could not load interests', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fetch more than needed so we can filter out the current article
        $candidates = $this->articleSearch->findByTopics($searchTags, self::MAX_RESULTS + 5);

        // Parse current article's slug from coordinate
        $parts = explode(':', $this->coordinate, 3);
        $currentSlug = $parts[2] ?? null;
        $currentPubkey = $parts[1] ?? null;

        $results = [];
        $seenCoordinates = [];
        foreach ($candidates as $article) {
            $articleSlug = $article->getSlug();
            $articlePubkey = $article->getPubkey();

            if ($articleSlug === null || $articlePubkey === null) {
                continue;
            }

            // Skip the article we're currently viewing
            if ($articleSlug === $currentSlug && $articlePubkey === $currentPubkey) {
                continue;
            }

            // Keep only the newest candidate per article coordinate.
            $coordinateKey = $articlePubkey . ':' . $articleSlug;
            if (isset($seenCoordinates[$coordinateKey])) {
                continue;
            }
            $seenCoordinates[$coordinateKey] = true;

            $results[] = $article;
            if (count($results) >= self::MAX_RESULTS) {
                break;
            }
        }

        return $results;
    }
}

