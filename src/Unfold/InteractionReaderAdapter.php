<?php

declare(strict_types=1);

namespace App\Unfold;

use App\Message\RefreshReaderInteractionsMessage;
use DecentNewsroom\UnfoldBundle\Contract\Comment;
use DecentNewsroom\UnfoldBundle\Contract\InteractionPage;
use DecentNewsroom\UnfoldBundle\Contract\InteractionReaderInterface;
use DecentNewsroom\UnfoldBundle\Contract\InteractionState;
use DecentNewsroom\UnfoldBundle\Contract\InteractionTarget;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Doctrine\DBAL\Connection;

final readonly class InteractionReaderAdapter implements InteractionReaderInterface
{
    private const REFRESH_DEBOUNCE_SECONDS = 15;

    public function __construct(
        private InteractionHydrator $hydrator,
        private MessageBusInterface $bus,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        private ?Connection $connection = null,
    ) {
    }

    public function target(string $publicationCoordinate, string $coordinate): ?InteractionTarget
    {
        return $this->hydrator->target($publicationCoordinate, $coordinate);
    }

    public function thread(InteractionTarget $target, ?string $cursor = null): InteractionPage
    {
        return $this->hydrator->thread($target, $cursor);
    }

    public function parent(InteractionTarget $target, string $eventId): ?Comment
    {
        return $this->hydrator->parent($target, $eventId);
    }

    public function state(InteractionTarget $target, ?string $readerPubkey = null): InteractionState
    {
        return $this->hydrator->state($target, $readerPubkey);
    }

    public function refresh(InteractionTarget $target): void
    {
        $cacheKey = 'unfold_reader_refresh:' . hash('sha256', $target->publicationCoordinate . "\0" . $target->post->coordinate);
        $lockKey = (int) hexdec(substr(hash('sha256', $cacheKey), 0, 15));
        $locked = false;
        $reserved = false;
        try {
            if ($this->connection === null) {
                throw new \RuntimeException('Reader refresh lock unavailable');
            }
            $locked = in_array($this->connection->fetchOne('SELECT pg_try_advisory_lock(:key)', ['key' => $lockKey]), [true, 1, '1', 't'], true);
            if (!$locked) {
                return;
            }
            $item = $this->cache->getItem($cacheKey);
            if ($item->isHit()) {
                return;
            }
            $item->set(time());
            $item->expiresAfter(self::REFRESH_DEBOUNCE_SECONDS);
            $reserved = true;
            if (!$this->cache->save($item)) {
                throw new \RuntimeException('Reader refresh reservation failed');
            }
            $this->bus->dispatch(new RefreshReaderInteractionsMessage($target->publicationCoordinate, $target->post->coordinate));
        } catch (\Throwable $e) {
            if ($reserved) {
                try {
                    if (!$this->cache->deleteItem($cacheKey)) {
                        $this->logger->error('Reader refresh reservation could not be cleared', ['key' => $cacheKey]);
                    }
                } catch (\Exception $cleanupError) {
                    $this->logger->error('Reader refresh reservation cleanup failed', ['error' => $cleanupError->getMessage()]);
                }
            }
            $this->logger->error('Reader interaction refresh could not be queued', [
                'coordinate' => $target->post->coordinate,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException('unfold_interactions.unavailable', 0, $e);
        } finally {
            if ($locked) {
                try {
                    $this->connection->fetchOne('SELECT pg_advisory_unlock(:key)', ['key' => $lockKey]);
                } catch (\Exception $e) {
                    throw new \RuntimeException('unfold_interactions.unavailable', 0, $e);
                }
            }
        }
    }
}
