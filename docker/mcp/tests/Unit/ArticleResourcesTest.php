<?php

declare(strict_types=1);

namespace DecentNewsroom\Mcp\Tests\Unit;

use DecentNewsroom\Mcp\Client\NewsroomApiClient;
use DecentNewsroom\Mcp\Resource\ArticleResources;
use PHPUnit\Framework\TestCase;

final class ArticleResourcesTest extends TestCase
{
    private const COORDINATE = '30023:2b998b04e2a1fe6855b2e0ab10bb92b774b5dfa0f78926c7a65ae08086727e47:chaos-job-galatians-and-coldcard';

    public function testPercentEncodedCoordinateIsDecodedBeforeLookup(): void
    {
        $encoded = rawurlencode(self::COORDINATE);

        $client = $this->createMock(NewsroomApiClient::class);
        $client->expects($this->once())
            ->method('getArticle')
            ->with(self::COORDINATE)
            ->willReturn(['coordinate' => self::COORDINATE, 'title' => 'Chaos']);

        $resources = new ArticleResources($client);
        $result = $resources->article($encoded);

        self::assertSame('Chaos', $result['title']);
    }

    public function testAlreadyDecodedCoordinatePassesThroughUnchanged(): void
    {
        $client = $this->createMock(NewsroomApiClient::class);
        $client->expects($this->once())
            ->method('getArticle')
            ->with(self::COORDINATE)
            ->willReturn(['coordinate' => self::COORDINATE]);

        $resources = new ArticleResources($client);
        $resources->article(self::COORDINATE);
    }

    public function testMissingArticleReturnsErrorWithDecodedCoordinate(): void
    {
        $client = $this->createMock(NewsroomApiClient::class);
        $client->method('getArticle')->willReturn(null);

        $resources = new ArticleResources($client);
        $result = $resources->article(rawurlencode(self::COORDINATE));

        self::assertArrayHasKey('error', $result);
        self::assertSame(self::COORDINATE, $result['coordinate']);
    }
}
