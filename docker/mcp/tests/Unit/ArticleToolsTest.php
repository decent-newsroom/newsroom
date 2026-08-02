<?php

declare(strict_types=1);

namespace DecentNewsroom\Mcp\Tests\Unit;

use DecentNewsroom\Mcp\Client\NewsroomApiClient;
use DecentNewsroom\Mcp\Tool\ArticleTools;
use PHPUnit\Framework\TestCase;

final class ArticleToolsTest extends TestCase
{
    public function testSearchArticlesDelegatesToClient(): void
    {
        $client = $this->createMock(NewsroomApiClient::class);
        $client->expects($this->once())
            ->method('search')
            ->with('nostr', 5, 0)
            ->willReturn([['title' => 'A']]);

        $tools = new ArticleTools($client);
        $result = $tools->searchArticles('nostr', 5);

        self::assertSame([['title' => 'A']], $result);
    }

    public function testGetArticleReturnsArticleWhenFound(): void
    {
        $client = $this->createMock(NewsroomApiClient::class);
        $client->method('getArticle')->willReturn(['coordinate' => '30023:abc:slug', 'title' => 'Hello']);

        $tools = new ArticleTools($client);
        $result = $tools->getArticle('30023:abc:slug');

        self::assertSame('Hello', $result['title']);
    }

    public function testGetArticleReturnsErrorWhenMissing(): void
    {
        $client = $this->createMock(NewsroomApiClient::class);
        $client->method('getArticle')->willReturn(null);

        $tools = new ArticleTools($client);
        $result = $tools->getArticle('30023:abc:missing');

        self::assertArrayHasKey('error', $result);
        self::assertSame('30023:abc:missing', $result['coordinate']);
    }

    public function testListByTopicReindexesTopics(): void
    {
        $client = $this->createMock(NewsroomApiClient::class);
        $client->expects($this->once())
            ->method('byTopic')
            ->with(['nostr', 'bitcoin'], 12, 0)
            ->willReturn([]);

        $tools = new ArticleTools($client);
        // Pass a non-sequentially-keyed array to verify array_values normalisation.
        $tools->listByTopic([2 => 'nostr', 5 => 'bitcoin']);
    }
}
