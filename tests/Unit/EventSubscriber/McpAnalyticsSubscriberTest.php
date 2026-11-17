<?php

declare(strict_types=1);

namespace AppTests\Unit\EventSubscriber;

use App\EventSubscriber\McpAnalyticsSubscriber;
use App\Service\Admin\McpAnalytics;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class McpAnalyticsSubscriberTest extends TestCase
{
    /** @dataProvider operationProvider */
    public function testAuthenticatedRequestsAreRecorded(string $method, string $path, string $operation): void
    {
        $request = Request::create($path . '?q=private-search', $method);
        $request->headers->set('X-Newsroom-Client', 'mcp');
        $request->headers->set('X-Internal-Token', 'secret');
        $analytics = $this->createMock(McpAnalytics::class);
        $analytics->expects(self::once())->method('record')->with(
            $operation, 503, self::callback(static fn (int $us): bool => $us >= 0),
        );
        $subscriber = new McpAnalyticsSubscriber($analytics, 'secret');
        $kernel = $this->createMock(HttpKernelInterface::class);
        $subscriber->onRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $response = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new Response('', 503));
        $subscriber->onResponse($response);
        $subscriber->onResponse($response);
        self::assertFalse($request->attributes->has('_mcp_analytics'));
    }

    public static function operationProvider(): array
    {
        return [
            ['GET', '/internal/api/articles/search', 'search_articles'],
            ['GET', '/internal/api/articles/get', 'get_article'],
            ['GET', '/internal/api/articles/latest', 'list_latest'],
            ['GET', '/internal/api/articles/by-author', 'list_by_author'],
            ['GET', '/internal/api/articles/by-topic', 'list_by_topic'],
            ['GET', '/internal/api/articles/topics', 'list_topics'],
            ['POST', '/books/api/publications/search', 'search_books'],
            ['POST', '/books/api/publications/sections/search', 'search_book_sections'],
            ['GET', '/books/api/events/' . str_repeat('a', 64), 'get_book'],
        ];
    }

    /** @dataProvider ignoredProvider */
    public function testUnattributedRequestsAreIgnored(
        string $path, string $method, string $token, string $marker, string $configuredToken, int $type,
    ): void {
        $request = Request::create($path, $method);
        $request->headers->set('X-Newsroom-Client', $marker);
        $request->headers->set('X-Internal-Token', $token);
        $analytics = $this->createMock(McpAnalytics::class);
        $analytics->expects(self::never())->method('record');
        $subscriber = new McpAnalyticsSubscriber($analytics, $configuredToken);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $subscriber->onRequest(new RequestEvent($kernel, $request, $type));
        $subscriber->onResponse(new ResponseEvent($kernel, $request, $type, new Response()));
        self::assertFalse($request->attributes->has('_mcp_analytics'));
    }

    public static function ignoredProvider(): array
    {
        return [
            ['/internal/api/articles/latest', 'GET', 'wrong', 'mcp', 'secret', HttpKernelInterface::MAIN_REQUEST],
            ['/internal/api/articles/latest', 'GET', 'secret', '', 'secret', HttpKernelInterface::MAIN_REQUEST],
            ['/internal/api/articles/latest', 'GET', '', 'mcp', '', HttpKernelInterface::MAIN_REQUEST],
            ['/internal/api/articles/latest', 'GET', 'secret', 'mcp', 'secret', HttpKernelInterface::SUB_REQUEST],
            ['/books/api/publications/search', 'POST', '', 'mcp', 'secret', HttpKernelInterface::MAIN_REQUEST],
            ['/books/api/publications/search', 'POST', '', '', 'secret', HttpKernelInterface::MAIN_REQUEST],
            ['/books/api/publications/search', 'GET', 'secret', 'mcp', 'secret', HttpKernelInterface::MAIN_REQUEST],
            ['/internal/api/articles/unknown', 'GET', 'secret', 'mcp', 'secret', HttpKernelInterface::MAIN_REQUEST],
            ['/mcp', 'GET', 'secret', 'mcp', 'secret', HttpKernelInterface::MAIN_REQUEST],
        ];
    }
}
