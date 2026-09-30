<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Event;
use App\Enum\KindsEnum;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Reads a chapter from the Books API as a transient Event; it never persists it. */
class BooksChapterLookup
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(param: 'bookshelf.books_api_base_url')]
        private readonly string $booksApiBaseUrl,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    public function find(string $pubkey, string $identifier): ?Event
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $pubkey) || $identifier === '') {
            return null;
        }

        $pubkey = strtolower($pubkey);
        $cacheItem = null;
        try {
            if ($this->cache->getItem('books_chapter_api_unavailable')->isHit()) {
                return null;
            }
            $cacheItem = $this->cache->getItem('books_chapter_' . hash('sha256', $pubkey . ':' . $identifier));
            if ($cacheItem->isHit()) {
                $cached = $cacheItem->get();
                return is_array($cached) ? $this->hydrate($cached, $pubkey, $identifier) : null;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Books chapter cache read failed.', ['error' => $e->getMessage()]);
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                rtrim($this->booksApiBaseUrl, '/') . '/books/api/events/filter',
                [
                    'headers' => ['Accept' => 'application/json'],
                    'timeout' => 3.0,
                    'json' => [
                        'authors' => [$pubkey],
                        'kinds' => [KindsEnum::PUBLICATION_CONTENT->value],
                        '#d' => [$identifier],
                        'limit' => 10,
                    ],
                ],
            );
            if ($response->getStatusCode() >= 400) {
                if ($response->getStatusCode() >= 500) {
                    $this->markApiUnavailable();
                }
                return null;
            }

            $best = null;
            foreach ($response->toArray(false) as $candidate) {
                if (!is_array($candidate) || $this->hydrate($candidate, $pubkey, $identifier) === null) {
                    continue;
                }
                if ($best === null || (int) $candidate['created_at'] > (int) $best['created_at']) {
                    $best = $candidate;
                }
            }

            if ($cacheItem !== null) {
                try {
                    $cacheItem->set($best ?? false);
                    $cacheItem->expiresAfter($best === null ? 60 : 300);
                    $this->cache->save($cacheItem);
                } catch (\Throwable $e) {
                    $this->logger->warning('Books chapter cache write failed.', ['error' => $e->getMessage()]);
                }
            }

            return $best === null ? null : $this->hydrate($best, $pubkey, $identifier);
        } catch (\Throwable $e) {
            $this->markApiUnavailable();
            $this->logger->warning('Books API chapter lookup failed.', [
                'coordinate' => KindsEnum::PUBLICATION_CONTENT->value . ':' . $pubkey . ':' . $identifier,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function markApiUnavailable(): void
    {
        try {
            $item = $this->cache->getItem('books_chapter_api_unavailable');
            $item->set(true);
            $item->expiresAfter(15);
            $this->cache->save($item);
        } catch (\Throwable) {
            // An unavailable cache must not prevent the relay fallback.
        }
    }

    /** @param array<string, mixed> $event */
    private function hydrate(array $event, string $pubkey, string $identifier): ?Event
    {
        if (($event['kind'] ?? null) !== KindsEnum::PUBLICATION_CONTENT->value
            || ($event['pubkey'] ?? null) !== $pubkey
            || !is_string($event['id'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/i', $event['id'])
            || !is_string($event['content'] ?? null)
            || !is_numeric($event['created_at'] ?? null)
            || !is_string($event['sig'] ?? null)
            || !is_array($event['tags'] ?? null)) {
            return null;
        }

        $hasIdentifier = false;
        foreach ($event['tags'] as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'd' && ($tag[1] ?? null) === $identifier) {
                $hasIdentifier = true;
                break;
            }
        }
        if (!$hasIdentifier) {
            return null;
        }

        $chapter = new Event();
        $chapter->setId($event['id']);
        $chapter->setKind(KindsEnum::PUBLICATION_CONTENT->value);
        $chapter->setPubkey($pubkey);
        $chapter->setContent($event['content']);
        $chapter->setCreatedAt((int) $event['created_at']);
        $chapter->setTags($event['tags']);
        $chapter->setSig($event['sig']);
        $chapter->setDTag($identifier);

        return $chapter;
    }
}
