<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Enum\AuthorContentType;
use App\Message\FetchAuthorContentMessage;
use App\Message\RevalidateProfileCacheMessage;
use App\MessageHandler\RevalidateProfileCacheHandler;
use App\ReadModel\RedisView\RedisViewFactory;
use App\Repository\ArticleRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Cache\RedisViewStore;
use App\Service\DispatchThrottle;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RevalidateProfileCacheHandlerTest extends TestCase
{
    public function testProfileRevalidationDispatchesAuthorFetchWithoutRelayOverride(): void
    {
        $pubkey = str_repeat('a', 64);
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(function (object $message) use ($pubkey): bool {
                self::assertInstanceOf(FetchAuthorContentMessage::class, $message);
                self::assertSame($pubkey, $message->getPubkey());
                self::assertSame(AuthorContentType::publicTypes(), $message->getContentTypes());
                self::assertFalse($message->isOwner());
                self::assertNull($message->getRelays());
                self::assertGreaterThanOrEqual(time() - 21600, $message->getSince());
                self::assertLessThanOrEqual(time(), $message->getSince());

                return true;
            }))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $throttle = $this->createMock(DispatchThrottle::class);
        $throttle->expects(self::once())
            ->method('acquire')
            ->with('author_content_fetch', $pubkey, 300)
            ->willReturn(true);

        $viewStore = $this->createMock(RedisViewStore::class);
        $viewStore->expects(self::once())
            ->method('storeProfileTabData')
            ->with($pubkey, 'unknown', []);

        $handler = new RevalidateProfileCacheHandler(
            $viewStore,
            $this->createMock(RedisViewFactory::class),
            $this->createMock(RedisCacheService::class),
            $this->createMock(ArticleRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $messageBus,
            $this->createMock(LoggerInterface::class),
            $throttle,
        );

        $handler(new RevalidateProfileCacheMessage($pubkey, 'unknown'));
    }
}