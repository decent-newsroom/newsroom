<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Entity\Article;
use App\Repository\ArticleRepository;
use App\Repository\UserEntityRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Nostr\NostrKeyService;

final readonly class FeaturedWritersFeed
{
    public function __construct(
        private UserEntityRepository $users,
        private ArticleRepository $articles,
        private RedisCacheService $cache,
    ) {}

    /** @return array{articles: array<Article>, authorsMetadata: array<string, \stdClass>} */
    public function load(): array
    {
        $pubkeys = [];
        $keys = new NostrKeyService();
        foreach ($this->users->findFeaturedWriters() as $user) {
            $npub = $user->getNpub();
            if (str_starts_with(strtolower(trim((string) $npub)), 'npub1')) {
                $pubkeys[] = $keys->convertToHex($npub);
            }
        }
        $articles = $pubkeys === [] ? [] : $this->articles->findLatestByPubkeys($pubkeys, 50);
        $metadata = $this->cache->getMultipleMetadata(array_values(array_unique(
            array_map(static fn ($article) => $article->getPubkey(), $articles),
        )));
        $authorsMetadata = [];
        foreach ($metadata as $pubkey => $profile) {
            $authorsMetadata[$pubkey] = $profile->toStdClass();
        }

        return ['articles' => $articles, 'authorsMetadata' => $authorsMetadata];
    }
}
