<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\EventWorkspace;

use App\Entity\Event;
use App\Entity\User;
use App\Message\FetchEventFromRelaysMessage;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventWorkspaceSourceAdapter;
use App\Service\EventWorkspace\Metrics\WorkspaceMetrics;
use App\Service\EventWorkspace\Metrics\WorkspaceMetricsStore;
use App\Service\EventWorkspace\Metrics\WorkspaceMetricsSubscriber;
use App\Service\EventWorkspace\Metrics\WorkspaceQueryMiddleware;
use App\Service\EventWorkspace\PublicationTocDiscovery;
use App\Service\EventWorkspace\PublicationTocPage;
use App\Service\EventWorkspace\PublicationTocTraversalSigner;
use App\Service\EventWorkspace\TargetPreviewResolver;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Nostr\RelayRegistry;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use App\Tests\Fixtures\WorkspaceMetricsFailureStream;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

final class WorkspaceRuntimeMetricsTest extends TestCase
{
    public function testDisabledAndOutsideScopeDoNotProduceSamples(): void
    {
        $disabled = new WorkspaceMetrics(false);
        $disabled->begin();
        $disabled->query();
        $disabled->queuedFetch();
        $disabled->resolution('available');
        self::assertNull($disabled->finish(200));
        $driver = $this->createStub(\Doctrine\DBAL\Driver::class);
        self::assertSame($driver, (new WorkspaceQueryMiddleware($disabled))->wrap($driver));

        $metrics = new WorkspaceMetrics(true);
        $metrics->query();
        $metrics->resolution('available');
        self::assertNull($metrics->finish(200));
    }

    public function testRealDbalMiddlewareCountsExecutionAttemptsWithoutRecordingSqlOrParameters(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite is required.');
        }
        $metrics = new WorkspaceMetrics(true);
        $config = new Configuration();
        $config->setMiddlewares([new WorkspaceQueryMiddleware($metrics)]);
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        try {
            $db->executeQuery('SELECT 1');
            $metrics->begin();
            $db->executeQuery('SELECT ?', ['private-parameter'])->fetchOne();
            $db->executeQuery('SELECT 1')->fetchOne();
            $db->executeStatement('CREATE TABLE fixture (value integer)');
            try {
                $db->executeQuery('SELECT * FROM missing_fixture');
                self::fail('Expected a database failure.');
            } catch (\Doctrine\DBAL\Exception) {
            }
            $sample = $metrics->finish(200);
            self::assertSame(4, $sample['queries']);
            self::assertSame(1, $sample['queries_1_5']);
            self::assertStringNotContainsString('private-parameter', json_encode($sample, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('SELECT', json_encode($sample, JSON_THROW_ON_ERROR));
        } finally {
            $db->close();
        }
    }

    public function testStoreSkipsLockedOrInvalidDataWithoutOverwritingIt(): void
    {
        $path = dirname(__DIR__, 4) . '/var/workspace-metrics-test-' . bin2hex(random_bytes(8)) . '.json';
        $metrics = new WorkspaceMetrics(true);
        $metrics->begin();
        $sample = $metrics->finish(200);
        $store = new WorkspaceMetricsStore($path);
        $lock = fopen($path, 'c+');
        self::assertIsResource($lock);
        try {
            self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
            self::assertFalse($store->add($sample));
            self::assertSame('', file_get_contents($path));
            flock($lock, LOCK_UN);
            self::assertTrue($store->add($sample));
            self::assertSame(1, json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['requests']);
            file_put_contents($path, 'invalid');
            self::assertFalse($store->add($sample));
            self::assertSame('invalid', file_get_contents($path));
        } finally {
            fclose($lock);
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testUnavailableStoreDoesNotChangeTheResponse(): void
    {
        $path = dirname(__DIR__, 4) . '/var/nonexistent-metrics-' . bin2hex(random_bytes(8)) . '/metrics.json';
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')
            ->with('Workspace metrics aggregation failed; some samples may be lost.', []);
        $subscriber = new WorkspaceMetricsSubscriber(new WorkspaceMetrics(true), new WorkspaceMetricsStore($path), $logger);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/fixture');
        $request->attributes->set('_route', 'reading_nook_publication_toc');
        $response = new Response('fixture', 200, ['Cache-Control' => 'private, no-store']);
        $cacheControl = $response->headers->get('Cache-Control');
        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('fixture', $response->getContent());
        self::assertSame($cacheControl, $response->headers->get('Cache-Control'));
        self::assertFileDoesNotExist($path);
        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
    }

    /** @dataProvider streamFailures */
    public function testIoFailuresAreReportedWithoutChangingResponse(string $mode): void
    {
        self::assertTrue(stream_wrapper_register('workspacestorage', WorkspaceMetricsFailureStream::class));
        WorkspaceMetricsFailureStream::$mode = $mode;
        try {
            $logger = $this->createMock(LoggerInterface::class);
            $logger->expects(self::once())->method('warning')
                ->with('Workspace metrics aggregation failed; some samples may be lost.', []);
            $subscriber = new WorkspaceMetricsSubscriber(
                new WorkspaceMetrics(true),
                new WorkspaceMetricsStore('workspacestorage://sensitive-synthetic-path'),
                $logger,
            );
            $kernel = $this->createStub(HttpKernelInterface::class);
            $request = Request::create('/sensitive-synthetic-path');
            $request->attributes->set('_route', 'reading_nook_publication_toc');
            $response = new Response('fixture', 200, ['Cache-Control' => 'private, no-store']);
            $cacheControl = $response->headers->get('Cache-Control');
            $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
            $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('fixture', $response->getContent());
            self::assertSame($cacheControl, $response->headers->get('Cache-Control'));
        } finally {
            WorkspaceMetricsFailureStream::$mode = '';
            stream_wrapper_unregister('workspacestorage');
        }
    }

    public static function streamFailures(): iterable
    {
        yield 'read exception' => ['read'];
        yield 'truncate failure' => ['truncate'];
        yield 'short write' => ['short'];
        yield 'flush failure' => ['flush'];
    }

    public function testStableExactEventRouteIsInstrumented(): void
    {
        $path = dirname(__DIR__, 4) . '/var/workspace-metrics-test-' . bin2hex(random_bytes(8)) . '.json';
        $subscriber = new WorkspaceMetricsSubscriber(new WorkspaceMetrics(true), new WorkspaceMetricsStore($path), new NullLogger());
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/private-event');
        $request->attributes->set('_route', 'reading_nook_drive_event');
        try {
            $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
            $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response()));
            $data = file_get_contents($path);
            self::assertSame(1, json_decode($data, true, flags: JSON_THROW_ON_ERROR)['requests']);
            self::assertStringNotContainsString('private-event', $data);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testFragmentScopesQueueAndFailureIntoOnlyAggregateLocalData(): void
    {
        $path = dirname(__DIR__, 4) . '/var/workspace-metrics-test-' . bin2hex(random_bytes(8)) . '.json';
        $metrics = new WorkspaceMetrics(true);
        $subscriber = new WorkspaceMetricsSubscriber($metrics, new WorkspaceMetricsStore($path), new NullLogger());
        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = Request::create('/private-id?secret=value');
        $request->attributes->set('_route', 'reading_nook_publication_toc_discovery');
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($subscriber);
        $sender = $this->createStub(SenderInterface::class);
        $sender->method('send')->willReturnCallback(static fn (Envelope $envelope): Envelope => $envelope);
        $locator = $this->createStub(SendersLocatorInterface::class);
        $locator->method('getSenders')->willReturn(['test' => $sender]);
        $bus = new MessageBus([new SendMessageMiddleware($locator, $dispatcher)]);
        $message = new FetchEventFromRelaysMessage('private-lookup', 'naddr', relays: ['wss://private.example']);

        try {
            $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
            $metrics->query();
            $metrics->resolution('missing');
            $subrequest = Request::create('/unrelated-subrequest');
            $subscriber->onRequest(new RequestEvent($kernel, $subrequest, HttpKernelInterface::SUB_REQUEST));
            $subscriber->onResponse(new ResponseEvent($kernel, $subrequest, HttpKernelInterface::SUB_REQUEST, new Response()));
            $bus->dispatch($message);
            $response = new Response('body', 200, ['Cache-Control' => 'private, no-store']);
            $cacheControl = $response->headers->get('Cache-Control');
            $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
            self::assertSame($cacheControl, $response->headers->get('Cache-Control'));
            $subscriber->onFinish(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

            $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
            $metrics->resolution('failed');
            $metrics->resolution('private-arbitrary-label');
            $subscriber->onFinish(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

            $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
            $subscriber->onException(new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('private error')));
            $subscriber->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 503)));
            $subscriber->onFinish(new FinishRequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));

            $other = Request::create('/unrelated');
            $other->attributes->set('_route', 'home');
            $subscriber->onRequest(new RequestEvent($kernel, $other, HttpKernelInterface::MAIN_REQUEST));
            $metrics->query();
            $bus->dispatch($message);
            $subscriber->onResponse(new ResponseEvent($kernel, $other, HttpKernelInterface::MAIN_REQUEST, new Response()));

            $raw = file_get_contents($path);
            self::assertIsString($raw);
            $aggregate = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(3, $aggregate['requests']);
            self::assertSame(1, $aggregate['queries']);
            self::assertSame(1, $aggregate['queued_fetches']);
            self::assertSame(1, $aggregate['http_2xx']);
            self::assertSame(1, $aggregate['no_response']);
            self::assertSame(1, $aggregate['http_5xx']);
            self::assertSame(2, $aggregate['exceptions']);
            self::assertSame(1, $aggregate['resolution_missing']);
            self::assertSame(1, $aggregate['resolution_failed']);
            self::assertSame(1, $aggregate['resolution_other']);
            self::assertSame(2, $aggregate['fetches_0']);
            self::assertSame(1, $aggregate['fetches_1']);
            foreach (['private-id', 'secret', 'private-lookup', 'private.example', 'body', 'arbitrary-label'] as $sensitive) {
                self::assertStringNotContainsString($sensitive, $raw);
            }
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testTocAndColdDiscoveryReportActualResolutionStatuses(): void
    {
        $metrics = new WorkspaceMetrics(true);
        $metrics->begin();
        $owner = str_repeat('a', 64);
        $source = new Event();
        $source->setId(str_repeat('b', 64));
        $source->setKind(30040);
        $source->setPubkey($owner);
        $source->setCreatedAt(123);
        $source->setTags([['d', 'book'], ['a', '30040:' . $owner . ':missing'], ['a', 'invalid']]);
        $identities = new RecordIdentityService();
        $presentations = new EventPresentationFactory($identities, new NostrNip19Service());
        $events = $this->createStub(EventRepository::class);
        $events->method('findPublicationIndexTargets')->willReturn(['coordinates' => [], 'eventIds' => []]);
        $page = new PublicationTocPage(
            new EventWorkspaceSourceAdapter($identities), $presentations, $events,
            $this->createStub(DeletedEventRepository::class), $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(HiddenCoordinateRepository::class), new PublicationTocTraversalSigner('fixture'), $metrics,
        );
        $page->fromAuthorizedEvent($source);
        $reference = $presentations->fromAuthorizedEvent($source)->references[0];
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::exactly(4))->method('eval')->willReturnOnConsecutiveCalls([1, 60], [0, 60], [-1, 30], false);
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $relays = $this->createStub(RelayRegistry::class);
        $relays->method('getContentRelays')->willReturn([]);
        $discovery = new PublicationTocDiscovery($redis, $bus, $relays, new NullLogger(), 'fixture', $metrics);
        self::assertSame('loading', $discovery->request($owner, $reference)['status']);
        self::assertSame('loading', $discovery->request($owner, $reference)['status']);
        self::assertSame('limited', $discovery->request($owner, $reference)['status']);
        try {
            $discovery->request($owner, $reference);
            self::fail('Expected discovery reservation failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('Discovery reservation unavailable.', $error->getMessage());
        }

        $sample = $metrics->finish(200);
        self::assertSame(1, $sample['resolution_unavailable']);
        self::assertSame(1, $sample['resolution_invalid']);
        self::assertSame(2, $sample['resolution_loading']);
        self::assertSame(1, $sample['resolution_limited']);
        self::assertSame(1, $sample['resolution_failed']);
        self::assertSame(0, $sample['queued_fetches'], 'Only a successful Messenger transport send event counts.');
    }

    public function testTargetResolverReportsAvailableMissingAndFailureWithoutChangingResults(): void
    {
        $metrics = new WorkspaceMetrics(true);
        $metrics->begin();
        $author = str_repeat('a', 64);
        $target = new Event();
        $target->setId(str_repeat('b', 64));
        $target->setKind(30040);
        $target->setPubkey($author);
        $target->setCreatedAt(123);
        $target->setTags([['d', 'present']]);
        $source = new Event();
        $source->setId(str_repeat('c', 64));
        $source->setKind(30040);
        $source->setPubkey($author);
        $source->setCreatedAt(123);
        $source->setTags([['d', 'source'], ['a', '30040:' . $author . ':present'], ['a', '30040:' . $author . ':missing']]);
        $factory = new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service());
        $references = $factory->fromAuthorizedEvent($source)->references;
        $user = new User();
        $user->setNpub(PublicKey::fromHex($author)->toBech32());
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $events = $this->createStub(EventRepository::class);
        $events->method('findWorkspacePreviewTargets')->willReturn([
            'coordinates' => ['30040:' . $author . ':present' => $target], 'eventIds' => [],
        ]);
        $mutes = $this->createStub(UserMuteListService::class);
        $mutes->method('getMutedPubkeysStrict')->willReturn([]);
        $resolver = new TargetPreviewResolver(
            $events, $this->createStub(DeletedEventRepository::class), $this->createStub(HiddenCoordinateRepository::class),
            $this->createStub(ContentAuthorAccessPolicy::class), $factory, $security, $mutes, $metrics,
        );
        $results = $resolver->resolvePage($references);
        self::assertSame(['available', 'missing'], array_map(static fn ($result): string => $result->status->value, array_values($results)));

        $failingMutes = $this->createStub(UserMuteListService::class);
        $failure = new \RuntimeException('fixture failure');
        $failingMutes->method('getMutedPubkeysStrict')->willThrowException($failure);
        $failingResolver = new TargetPreviewResolver(
            $events, $this->createStub(DeletedEventRepository::class), $this->createStub(HiddenCoordinateRepository::class),
            $this->createStub(ContentAuthorAccessPolicy::class), $factory, $security, $failingMutes, $metrics,
        );
        try {
            $failingResolver->resolvePage($references);
            self::fail('Expected the original resolver failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        $sample = $metrics->finish(200);
        self::assertSame(1, $sample['resolution_available']);
        self::assertSame(1, $sample['resolution_missing']);
        self::assertSame(1, $sample['resolution_failed']);
    }
}
