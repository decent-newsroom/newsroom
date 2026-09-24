<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Enum\AuthorContentType;
use App\Factory\ArticleFactory;
use App\Message\FetchAuthorContentMessage;
use App\MessageHandler\FetchAuthorContentHandler;
use App\Repository\EventRepository;
use App\Service\ArticleEventProjector;
use App\Service\Graph\EventIngestionListener;
use App\Service\Nostr\NostrRelayPool;
use App\Service\Nostr\RelayQueryRequest;
use App\Service\Nostr\UserRelayListService;
use App\Service\Cache\RedisViewStore;
use App\Util\CommonMark\Converter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;

final class FetchAuthorContentHandlerRelaySelectionTest extends TestCase
{
    public function testMissingRelayOverrideUsesBoundedAuthorContentRelays(): void
    {
        $pubkey = str_repeat('b', 64);
        $authorRelays = ['wss://local.example', 'wss://author-write.example'];

        $relayListService = $this->createMock(UserRelayListService::class);
        $relayListService->expects(self::once())
            ->method('getRelaysForAuthorContent')
            ->with($pubkey)
            ->willReturn($authorRelays);
        $relayListService->expects(self::never())->method('getRelaysForFetching');

        $relayPool = $this->createMock(NostrRelayPool::class);
        $relayPool->expects(self::once())
            ->method('executeRequest')
            ->with(self::callback(static function (RelayQueryRequest $request) use ($pubkey, $authorRelays): bool {
                self::assertSame($authorRelays, $request->getRelaySet()->getUrls());
                self::assertSame([30023], $request->getFilters()[0]['kinds']);
                self::assertSame([$pubkey], $request->getFilters()[0]['authors']);
                self::assertSame(15, $request->getTimeout());
                self::assertSame($pubkey, $request->getRequestedBy());

                return true;
            }))
            ->willReturn([]);

        $handler = new FetchAuthorContentHandler(
            $relayListService,
            $relayPool,
            $this->createMock(EventRepository::class),
            $this->createMock(ArticleFactory::class),
            $this->createMock(HubInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(EventIngestionListener::class),
            $this->createMock(Converter::class),
            $this->createMock(RedisViewStore::class),
            $this->createMock(ArticleEventProjector::class),
        );

        $handler(new FetchAuthorContentMessage($pubkey, [AuthorContentType::ARTICLES]));
    }
}