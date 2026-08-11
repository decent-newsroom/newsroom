<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mercury;

use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryApiClient;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryApiException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MercuryApiClientTest extends TestCase
{
    public function testSearchUnwrapsMercuryDataEnvelope(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://mercury.example/api/publications/search', $url);
            self::assertSame(
                ['q' => 'Aesop', 'limit' => 25],
                json_decode((string) $options['body'], true, flags: JSON_THROW_ON_ERROR),
            );

            return new MockResponse(json_encode([
                'data' => [
                    ['id' => str_repeat('a', 64), 'kind' => 30040],
                ],
            ], JSON_THROW_ON_ERROR));
        });

        $client = new MercuryApiClient($httpClient, 'https://mercury.example/');
        $events = $client->searchPublications('Aesop', 25);

        self::assertCount(1, $events);
        self::assertSame(30040, $events[0]['kind']);
    }

    public function testGetEventReturnsNullForNotFoundResponse(): void
    {
        $client = new MercuryApiClient(
            new MockHttpClient(new MockResponse('', ['http_code' => 404])),
            'https://mercury.example',
        );

        self::assertNull($client->getEvent(str_repeat('f', 64)));
    }

    public function testInvalidEnvelopeIsRejected(): void
    {
        $client = new MercuryApiClient(
            new MockHttpClient(new MockResponse('{"events":[]}')),
            'https://mercury.example',
        );

        $this->expectException(MercuryApiException::class);
        $this->expectExceptionMessage('invalid event list');

        $client->searchPublications('Aesop');
    }
}
