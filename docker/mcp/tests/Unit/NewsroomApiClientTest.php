<?php

declare(strict_types=1);

namespace DecentNewsroom\Mcp\Tests\Unit;

use DecentNewsroom\Mcp\Client\NewsroomApiClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NewsroomApiClientTest extends TestCase
{
    public function testSearchSendsTokenAndReturnsResults(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse(json_encode(['results' => [['title' => 'A'], ['title' => 'B']]]));
        });

        $client = new NewsroomApiClient($http, 'http://php', 'secret-token');
        $results = $client->search('nostr', 5, 10);

        self::assertCount(2, $results);
        self::assertSame('A', $results[0]['title']);
        self::assertStringContainsString('/internal/api/articles/search', $captured['url']);
        self::assertStringContainsString('q=nostr', urldecode($captured['url']));
        self::assertContains('X-Internal-Token: secret-token', $captured['options']['headers']);
    }

    public function testGetArticleReturnsResultObject(): void
    {
        $http = new MockHttpClient(
            new MockResponse(json_encode(['result' => ['coordinate' => '30023:abc:slug', 'title' => 'Hello']]))
        );

        $client = new NewsroomApiClient($http, 'http://php/', 'tok');
        $article = $client->getArticle('30023:abc:slug');

        self::assertNotNull($article);
        self::assertSame('Hello', $article['title']);
    }

    public function testGetArticleReturnsNullOn404(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 404]));

        $client = new NewsroomApiClient($http, 'http://php', 'tok');

        self::assertNull($client->getArticle('30023:abc:missing'));
    }

    public function testTopicCountsReturnsMap(): void
    {
        $http = new MockHttpClient(new MockResponse(json_encode(['counts' => ['nostr' => 12, 'bitcoin' => 3]])));

        $client = new NewsroomApiClient($http, 'http://php', 'tok');
        $counts = $client->topicCounts(['nostr', 'bitcoin']);

        self::assertSame(12, $counts['nostr']);
        self::assertSame(3, $counts['bitcoin']);
    }

    public function testServerErrorThrows(): void
    {
        $http = new MockHttpClient(new MockResponse('boom', ['http_code' => 500]));

        $client = new NewsroomApiClient($http, 'http://php', 'tok');

        $this->expectException(\RuntimeException::class);
        $client->latest();
    }
}
