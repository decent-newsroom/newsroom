<?php

declare(strict_types=1);

namespace App\Tests\Service\EventWorkspace;

use App\Service\EventWorkspace\Metrics\WorkspaceMetricsStore;
use App\Service\EventWorkspace\Metrics\WorkspaceMetricsSubscriber;
use App\Message\FetchEventFromRelaysMessage;
use App\Tests\Fixtures\WorkspaceMetricsKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\MessageSentToTransportsEvent;

final class WorkspaceMetricsKernelTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return WorkspaceMetricsKernel::class;
    }

    public function testRealRoutedRequestIsInstrumentedOnlyWhenEnabled(): void
    {
        $previous = getenv('EVENT_WORKSPACE_METRICS_ENABLED');
        $previousServer = $_SERVER['EVENT_WORKSPACE_METRICS_ENABLED'] ?? null;
        $previousEnv = $_ENV['EVENT_WORKSPACE_METRICS_ENABLED'] ?? null;
        $path = dirname(__DIR__, 3) . '/var/workspace-metrics-kernel-test-' . bin2hex(random_bytes(8)) . '.json';
        $disabledPath = $path . '.disabled';
        putenv('EVENT_WORKSPACE_METRICS_ENABLED=1');
        $_SERVER['EVENT_WORKSPACE_METRICS_ENABLED'] = $_ENV['EVENT_WORKSPACE_METRICS_ENABLED'] = '1';
        try {
            $kernel = self::bootKernel();
            self::getContainer()->set(WorkspaceMetricsStore::class, new WorkspaceMetricsStore($path));
            $request = Request::create('/reading-nook/publication-indexes/' . str_repeat('a', 64) . '/toc');
            $response = $kernel->handle($request);
            self::assertContains($response->getStatusCode(), [302, 401, 403]);
            self::assertFileExists($path);
            $raw = file_get_contents($path);
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(1, $data['requests']);
            self::assertSame(1, $data['http_3xx'] + $data['http_4xx']);
            self::assertStringNotContainsString(str_repeat('a', 64), $raw);
            self::assertStringNotContainsString('/reading-nook', $raw);

            $preview = Request::create('/api/preview/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'identifier' => 'synthetic-identifier', 'type' => 'unsupported', 'decoded' => [],
            ], JSON_THROW_ON_ERROR));
            self::assertSame(200, $kernel->handle($preview)->getStatusCode());
            self::assertSame(400, $kernel->handle(Request::create('/api/preview/', 'POST'))->getStatusCode());
            $raw = file_get_contents($path);
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(3, $data['requests']);
            self::assertSame(1, $data['http_2xx']);
            self::assertSame(1 + (int) $response->isClientError(), $data['http_4xx']);
            self::assertStringNotContainsString('synthetic-identifier', $raw);

            $subscriber = self::getContainer()->get(WorkspaceMetricsSubscriber::class);
            $scope = Request::create('/fixture');
            $scope->attributes->set('_route', 'reading_nook_publication_toc');
            $subscriber->onRequest(new RequestEvent($kernel, $scope, HttpKernelInterface::MAIN_REQUEST));
            self::getContainer()->get('doctrine')->getConnection()->executeQuery('SELECT ?', ['synthetic-query-parameter'])->fetchOne();
            $message = new FetchEventFromRelaysMessage('synthetic-lookup', 'naddr');
            self::getContainer()->get('event_dispatcher')->dispatch(new MessageSentToTransportsEvent(new Envelope($message), []));
            $subscriber->onResponse(new ResponseEvent($kernel, $scope, HttpKernelInterface::MAIN_REQUEST, new Response()));
            $raw = file_get_contents($path);
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(4, $data['requests']);
            self::assertGreaterThanOrEqual(1, $data['queries']);
            self::assertSame(1, $data['queued_fetches']);
            self::assertStringNotContainsString('synthetic-query-parameter', $raw);
            self::assertStringNotContainsString('synthetic-lookup', $raw);

            self::ensureKernelShutdown();
            putenv('EVENT_WORKSPACE_METRICS_ENABLED');
            unset($_SERVER['EVENT_WORKSPACE_METRICS_ENABLED'], $_ENV['EVENT_WORKSPACE_METRICS_ENABLED']);
            $kernel = self::bootKernel();
            self::getContainer()->set(WorkspaceMetricsStore::class, new WorkspaceMetricsStore($disabledPath));
            $disabledResponse = $kernel->handle(Request::create('/reading-nook/publication-indexes/' . str_repeat('a', 64) . '/toc'));
            self::assertSame($response->getStatusCode(), $disabledResponse->getStatusCode());
            self::assertSame($response->headers->get('Cache-Control'), $disabledResponse->headers->get('Cache-Control'));
            self::assertFileDoesNotExist($disabledPath);
        } finally {
            self::ensureKernelShutdown();
            if ($previous === false) {
                putenv('EVENT_WORKSPACE_METRICS_ENABLED');
            } else {
                putenv('EVENT_WORKSPACE_METRICS_ENABLED=' . $previous);
            }
            if ($previousServer === null) {
                unset($_SERVER['EVENT_WORKSPACE_METRICS_ENABLED']);
            } else {
                $_SERVER['EVENT_WORKSPACE_METRICS_ENABLED'] = $previousServer;
            }
            if ($previousEnv === null) {
                unset($_ENV['EVENT_WORKSPACE_METRICS_ENABLED']);
            } else {
                $_ENV['EVENT_WORKSPACE_METRICS_ENABLED'] = $previousEnv;
            }
            if (is_file($path)) {
                unlink($path);
            }
            if (is_file($disabledPath)) {
                unlink($disabledPath);
            }
        }
    }
}
