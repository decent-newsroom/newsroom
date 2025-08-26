<?php

namespace App\Service;

use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

readonly class RedisCacheService
{

    public function __construct(
        private NostrClient     $nostrClient,
        private CacheInterface  $redisCache,
        private LoggerInterface $logger
    )
    {
    }

    /**
     * @param string $npub
     * @return \stdClass
     */
    public function getMetadata(string $npub): \stdClass
    {
        $cacheKey = '0_' . $npub;
        try {
            return $this->redisCache->get($cacheKey, function (ItemInterface $item) use ($npub) {
                $item->expiresAfter(3600); // 1 hour, adjust as needed
                try {
                    $meta = $this->nostrClient->getNpubMetadata($npub);
                } catch (\Exception $e) {
                    $this->logger->error('Error getting user data.', ['exception' => $e]);
                    $meta = new \stdClass();
                    $content = new \stdClass();
                    $meta->name = substr($npub, 0, 8) . '…' . substr($npub, -4);
                    $meta->content = json_encode($content);
                }
                $this->logger->info('Metadata:', ['meta' => json_encode($meta)]);
                return json_decode($meta->content);
            });
        } catch (InvalidArgumentException $e) {
            $this->logger->error('Error getting user data.', ['exception' => $e]);
            $content = new \stdClass();
            $content->name = substr($npub, 0, 8) . '…' . substr($npub, -4);
            return $content;
        }
    }

    public function getRelays($npub)
    {
        $cacheKey = '10002_' . $npub;

        try {
            return $this->redisCache->get($cacheKey, function (ItemInterface $item) use ($npub) {
                $item->expiresAfter(3600); // 1 hour, adjust as needed
                try {
                    $relays = $this->nostrClient->getNpubRelays($npub);
                } catch (\Exception $e) {
                    $this->logger->error('Error getting user relays.', ['exception' => $e]);
                }
                return $relays ?? [];
            });
        } catch (InvalidArgumentException $e) {
            $this->logger->error('Error getting user relays.', ['exception' => $e]);
            return [];
        }
    }

    /**
     * Get a magazine index object by key.
     * @param string $key
     * @return object|null
     */
    public function getMagazineIndex(string $key): ?object
    {
        try {
            $item = $this->redisCache->getItem($key);
            return $item->isHit() ? $item->get() : null;
        } catch (\Exception $e) {
            $this->logger->error('Error fetching magazine index.', ['exception' => $e]);
            return null;
        }
    }

    /**
     * Update a magazine index by inserting a new article tag at the top.
     * @param string $key
     * @param array $articleTag The tag array, e.g. ['a', 'article:slug', ...]
     * @return bool
     */
    public function addArticleToIndex(string $key, array $articleTag): bool
    {
        $index = $this->getMagazineIndex($key);
        if (!$index || !isset($index->tags) || !is_array($index->tags)) {
            $this->logger->error('Invalid index object or missing tags array.');
            return false;
        }
        // Insert the new article tag at the top
        array_unshift($index->tags, $articleTag);
        try {
            $item = $this->redisCache->getItem($key);
            $item->set($index);
            $this->redisCache->save($item);
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error updating magazine index.', ['exception' => $e]);
            return false;
        }
    }
}
