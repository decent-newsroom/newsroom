<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Nostr\NostrKeyService;
use DecentNewsroom\UnfoldBundle\Contract\ContentPreviewProviderInterface;

final class ReadingListContentPreviewProvider implements ContentPreviewProviderInterface
{
    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly EventRepository $eventRepository,
        private readonly RedisCacheService $redisCacheService,
        private readonly NostrKeyService $nostrKeyService,
    ) {
    }

    public function findByCoordinates(array $coordinates): array
    {
        $parsed = [];
        $originals = [];
        foreach (array_unique($coordinates) as $coordinate) {
            $parts = explode(':', $coordinate, 3);
            if (count($parts) !== 3 || !ctype_digit($parts[0])
                || strlen($parts[0]) > 5 || (int) $parts[0] > 65535
                || preg_match('/^[a-fA-F0-9]{64}$/D', $parts[1]) !== 1) {
                continue;
            }

            $tuple = ['kind' => (int) $parts[0], 'pubkey' => strtolower($parts[1]), 'slug' => $parts[2]];
            $key = $tuple['kind'] . ':' . $tuple['pubkey'] . ':' . $tuple['slug'];
            $parsed[$key] = $tuple;
            $originals[$coordinate] = $key;
        }

        $titles = [];
        foreach (array_chunk($parsed, 500, preserve_keys: true) as $chunk) {
            $articles = $this->articleRepository->findByCoordinates(array_values($chunk));
            $missing = [];
            foreach ($chunk as $key => $tuple) {
                $article = $articles[$key] ?? null;
                if ($article !== null
                    && $article->getKind()?->value === $tuple['kind']
                    && $article->getPubkey() === $tuple['pubkey']
                    && $article->getSlug() === $tuple['slug']) {
                    $tags = $article->getRaw()['tags'] ?? [];
                    if (!$this->isScoped(is_array($tags) ? $tags : [])) {
                        $titles[$key] = $article->getTitle();
                    }
                    // Scoped projected articles must not fall back to a public event.
                    continue;
                }
                $missing[$key] = $tuple;
            }

            if ($missing === []) {
                continue;
            }
            $events = $this->eventRepository->findByCoordinates(array_keys($missing));
            foreach ($missing as $key => $tuple) {
                $event = $events[$key] ?? $this->eventRepository->findByNaddr(
                    $tuple['kind'], $tuple['pubkey'], $tuple['slug'],
                );
                if ($event === null || $event->getKind() !== $tuple['kind']
                    || $event->getPubkey() !== $tuple['pubkey']
                    || $this->tagValue($event->getTags(), 'd') !== $tuple['slug']
                    || $this->isScoped($event->getTags())) {
                    continue;
                }
                $titles[$key] = $this->tagValue($event->getTags(), 'title');
            }
        }

        if ($titles === []) {
            return [];
        }
        $pubkeys = [];
        foreach ($titles as $key => $title) {
            $pubkeys[$parsed[$key]['pubkey']] = true;
        }
        $metadata = $this->redisCacheService->getMultipleMetadata(array_keys($pubkeys));
        $authors = [];
        foreach ($pubkeys as $pubkey => $_) {
            $profile = $metadata[$pubkey] ?? null;
            $name = $profile?->displayName ?: $profile?->name;
            if (!$name) {
                $npub = $this->nostrKeyService->convertPublicKeyToBech32($pubkey);
                $name = substr($npub, 0, 8) . '...' . substr($npub, -4);
            }
            $authors[$pubkey] = $name;
        }

        $previews = [];
        foreach ($originals as $original => $key) {
            if (array_key_exists($key, $titles)) {
                $previews[$original] = ['title' => $titles[$key], 'author' => $authors[$parsed[$key]['pubkey']]];
            }
        }

        return $previews;
    }

    private function isScoped(array $tags): bool
    {
        foreach ($tags as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 's') {
                return true;
            }
        }

        return false;
    }

    private function tagValue(array $tags, string $name): ?string
    {
        foreach ($tags as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === $name) {
                return is_string($tag[1] ?? null) ? $tag[1] : null;
            }
        }

        return null;
    }
}
