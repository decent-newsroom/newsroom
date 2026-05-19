<?php

declare(strict_types=1);

namespace App\Twig\Components\Atoms;

use App\Dto\UserMetadata;
use App\Service\Cache\RedisCacheService;
use App\Service\Essayist\EssayistFeedService;
use App\Util\NostrKeyUtil;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Renders the N latest essays from the Essayist relay as a compact list.
 * Intended for sidebar use on the Essayist home page, wrapped in a Turbo Frame
 * that a Stimulus controller reloads periodically so the list stays fresh.
 */
#[AsTwigComponent]
final class LatestEssayistArticles
{
    /** @var object[] */
    public array $articles = [];

    /** How many articles to show (default 2). */
    public int $limit = 2;

    public function __construct(
        private readonly EssayistFeedService $feedService,
        private readonly RedisCacheService $redisCacheService,
    ) {}

    public function mount(): void
    {
        $cards = $this->feedService->fetchLatest($this->limit);

        // Resolve author display names from Redis
        $pubkeys = array_unique(array_column($cards, 'pubkey'));
        $pubkeys = array_values(array_filter($pubkeys, fn (string $pk): bool => NostrKeyUtil::isHexPubkey($pk)));

        $authorsMetadata = [];
        if (!empty($pubkeys)) {
            $raw = $this->redisCacheService->getMultipleMetadata($pubkeys);
            foreach ($raw as $pk => $m) {
                $authorsMetadata[$pk] = $m instanceof UserMetadata ? $m->toStdClass() : $m;
            }
        }

        foreach ($cards as $card) {
            $meta = $authorsMetadata[$card->pubkey] ?? null;
            $card->authorName = $meta?->display_name ?? $meta?->name ?? '';
        }

        $this->articles = $cards;
    }
}

