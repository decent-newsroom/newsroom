<?php

namespace App\Service;

use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

readonly class CacheService
{

    public function __construct(
        private NostrClient     $nostrClient,
        private CacheInterface  $cache,
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
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($npub, $cacheKey) {
                $item->expiresAfter(3600); // 1 hour, adjust as needed
                try {
                    $meta = $this->nostrClient->getNpubMetadata($npub);
                    $this->logger->info('Metadata:', ['meta' => json_encode($meta)]);
                    return json_decode($meta->content);
                } catch (\Exception $e) {
                    $this->logger->error('Error getting user data.', ['exception' => $e]);
                    throw new MetadataRetrievalException('Failed to retrieve metadata', 0, $e);
                }
            });
        } catch (\Exception|InvalidArgumentException $e) {
            $this->logger->error('Error getting user data.', ['exception' => $e]);
            $content = new \stdClass();
            $content->name = substr($npub, 0, 8) . '…' . substr($npub, -4);
            return $content;
        }
    }

    public function getRelays($npub)
    {
        $cacheKey = '3_' . $npub;
        try {
            return $this->cache->get($cacheKey, function (ItemInterface $item) use ($npub) {
                $item->expiresAfter(3600); // 1 hour
                try {
                    return $this->nostrClient->getNpubRelays($npub);
                } catch (\Exception $e) {
                    $this->logger->error('Error getting relays.', ['exception' => $e]);
                    return [];
                }
            });
        } catch (InvalidArgumentException $e) {
            $this->logger->error('Error getting relay data.', ['exception' => $e]);
            return [];
        }
    }
}
