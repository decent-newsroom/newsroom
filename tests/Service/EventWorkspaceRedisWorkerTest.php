<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Event;
use App\Message\FetchEventFromRelaysMessage;
use App\MessageHandler\FetchEventFromRelaysHandler;
use App\Repository\EventRepository;
use App\Service\ArticleEventProjector;
use App\Service\BooksChapterLookup;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\PublicationTocDiscovery;
use App\Service\GenericEventProjector;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Nostr\RelayRegistry;
use App\Service\Nostr\UserRelayListService;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Worker;

/**
 * Opt-in integration using an isolated Redis stream and discovery namespace.
 * The relay response, repository, and projector are synthetic.
 */
final class EventWorkspaceRedisWorkerTest extends TestCase
{
    /** @dataProvider relayOutcomes */
    public function testDuplicateDiscoveryUsesOneRealRedisMessageAndWorker(bool $found, bool $relayFails): void
    {
        if (($_SERVER['WORKSPACE_REDIS_INTEGRATION'] ?? $_ENV['WORKSPACE_REDIS_INTEGRATION'] ?? '') !== '1') {
            self::markTestSkipped('Set WORKSPACE_REDIS_INTEGRATION=1 to run the Redis worker integration.');
        }

        $redis = new \Redis();
        $redis->connect($_ENV['REDIS_HOST'], (int) $_ENV['REDIS_PORT'], 2.0);
        $redis->auth($_ENV['REDIS_PASSWORD']);

        $namespace = 'workspace-test-' . bin2hex(random_bytes(16));
        $stream = $namespace . ':messenger';
        $reader = str_repeat('a', 64);
        $author = str_repeat('b', 64);
        $source = new Event();
        $source->setId(str_repeat('c', 64));
        $source->setKind(30040);
        $source->setPubkey($reader);
        $source->setCreatedAt(123);
        $source->setContent('');
        $source->setTags([['d', 'book'], ['a', '30041:' . $author . ':chapter']]);
        $reference = (new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()))
            ->fromAuthorizedEvent($source)->references[0];
        $targetHash = hash('sha256', $reference->target);
        $readerHash = hash('sha256', $reader);
        $prefix = 'workspace:toc:' . $namespace . ':';
        $keys = [
            $stream,
            $stream . '__queue',
            $prefix . 'pending:' . $targetHash,
            $prefix . 'attempts:' . $readerHash . ':' . $targetHash,
            $prefix . 'budget:' . $readerHash,
        ];

        try {
            $raw = (object) [
                'id' => str_repeat('d', 64),
                'kind' => 30041,
                'pubkey' => $author,
                'tags' => [['d', 'chapter']],
            ];
            $repository = $this->createMock(EventRepository::class);
            $repository->expects(self::once())->method('findByNaddr')->with(30041, $author, 'chapter')->willReturn(null);
            $client = $this->createMock(NostrClient::class);
            $client->expects(self::once())->method('getEventByNaddr')->willReturnCallback(
                static function (array $decoded, bool $hintOnly, bool $allowRelayListNetworkFetch, ?int $gatewayTimeout, ?int $directTimeout) use ($author, $raw, $found, $relayFails): ?object {
                    self::assertSame(['kind' => 30041, 'pubkey' => $author, 'identifier' => 'chapter', 'relays' => ['wss://relay.example']], $decoded);
                    self::assertTrue($hintOnly);
                    self::assertFalse($allowRelayListNetworkFetch);
                    self::assertSame(10, $gatewayTimeout);
                    self::assertSame(5, $directTimeout);
                    if ($relayFails) {
                        throw new \RuntimeException('Synthetic relay failure');
                    }
                    return $found ? $raw : null;
                },
            );
            $projected = false;
            $projector = $this->createMock(GenericEventProjector::class);
            $projector->expects($found ? self::once() : self::never())->method('projectEventFromNostrEvent')
                ->with($raw, 'wss://relay.example')
                ->willReturnCallback(static function () use (&$projected): Event {
                    $projected = true;
                    return new Event();
                });
            $hub = $this->createMock(HubInterface::class);
            $hub->expects(self::never())->method('publish');
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects($relayFails ? self::once() : self::never())->method('warning');
            $handler = new FetchEventFromRelaysHandler(
                $client,
                $repository,
                $projector,
                $this->createMock(ArticleEventProjector::class),
                $this->createMock(UserRelayListService::class),
                $hub,
                $this->createMock(CacheItemPoolInterface::class),
                $logger,
                $this->createMock(BooksChapterLookup::class),
            );
            $transport = new RedisTransport(new Connection([
                'stream' => $stream,
                'group' => $namespace,
                'consumer' => $namespace,
                'delete_after_ack' => true,
            ], $redis), new PhpSerializer());
            $senders = new Container();
            $senders->set('isolated', $transport);
            $bus = new MessageBus([
                new SendMessageMiddleware(new SendersLocator([FetchEventFromRelaysMessage::class => ['isolated']], $senders)),
                new HandleMessageMiddleware(new HandlersLocator([FetchEventFromRelaysMessage::class => [$handler]])),
            ]);
            $relays = new RelayRegistry(new NullLogger(), contentRelays: ['wss://relay.example']);
            $discovery = new PublicationTocDiscovery($redis, $bus, $relays, new NullLogger(), $namespace);

            self::assertSame('loading', $discovery->request($reader, $reference)['status']);
            $redis->expire($stream, 600);
            self::assertSame('loading', $discovery->request($reader, $reference)['status']);
            self::assertSame(1, $redis->xLen($stream));

            $events = new EventDispatcher();
            $worker = new Worker(['isolated' => $transport], $bus, $events);
            $handled = 0;
            $failed = 0;
            $events->addListener(WorkerMessageHandledEvent::class, static function () use (&$handled, $worker): void {
                ++$handled;
                $worker->stop();
            });
            $events->addListener(WorkerMessageFailedEvent::class, static function () use (&$failed, $worker): void {
                ++$failed;
                $worker->stop();
            });
            $worker->run(['time_limit' => 5, 'sleep' => 10000]);

            self::assertSame(1, $handled);
            self::assertSame(0, $failed);
            self::assertSame($found, $projected);
            self::assertSame(0, $redis->xLen($stream));
            self::assertSame('loading', $discovery->status($reader, $reference)['status']);
            $redis->del($prefix . 'pending:' . $targetHash);
            self::assertSame('failed', $discovery->status($reader, $reference)['status']);
        } finally {
            $redis->del($keys);
            $redis->close();
        }
    }

    public static function relayOutcomes(): iterable
    {
        yield 'found' => [true, false];
        yield 'not found' => [false, false];
        yield 'relay failure' => [false, true];
    }
}
