<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\ChapterParentPublicationResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ChapterParentPublicationResolverTest extends TestCase
{
    public function testReturnsLocalPublicationWithoutCallingBooksApi(): void
    {
        $coordinate = $this->chapterCoordinate();
        $parent = $this->makeEvent([
            ['d', 'weekly'],
            ['title', 'Weekly publication'],
            ['a', $coordinate],
        ]);

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findReferencingEvents')
            ->with('a', $coordinate, [KindsEnum::PUBLICATION_INDEX->value], 1)
            ->willReturn([$parent]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::never())->method('request');

        $result = $this->resolver($repository, $httpClient)->resolve($coordinate);

        self::assertSame([
            'title' => 'Weekly publication',
            'eventId' => str_repeat('b', 64),
        ], $result);
    }

    public function testFallsBackToBooksApiAndAcceptsOnlyAnExactChapterReference(): void
    {
        $coordinate = $this->chapterCoordinate();
        $response = $this->response([
            [
                'kind' => KindsEnum::PUBLICATION_INDEX->value,
                'id' => str_repeat('b', 64),
                'tags' => [['d', 'wrong'], ['a', '30041:' . str_repeat('c', 64) . ':other']],
            ],
            [
                'kind' => KindsEnum::PUBLICATION_INDEX->value,
                'id' => str_repeat('d', 64),
                'tags' => [['d', 'weekly'], ['title', 'Books API publication'], ['a', $coordinate]],
            ],
        ]);

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())->method('findReferencingEvents')->willReturn([]);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('request')
            ->with('POST', 'https://books.example/books/api/events/filter', self::callback(
                static fn (array $options): bool => $options['json'] === [
                    'kinds' => [KindsEnum::PUBLICATION_INDEX->value],
                    '#a' => [$coordinate],
                    'limit' => 10,
                ],
            ))
            ->willReturn($response);

        $result = $this->resolver($repository, $httpClient)->resolve($coordinate);

        self::assertSame([
            'title' => 'Books API publication',
            'eventId' => str_repeat('d', 64),
        ], $result);
    }

    public function testReturnsNullWhenBooksApiFails(): void
    {
        $coordinate = $this->chapterCoordinate();
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(503);

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())->method('findReferencingEvents')->willReturn([]);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())->method('request')->willReturn($response);

        self::assertNull($this->resolver($repository, $httpClient)->resolve($coordinate));
    }

    public function testReturnsNullWhenBooksApiHasNoMatchingEvents(): void
    {
        $coordinate = $this->chapterCoordinate();
        $response = $this->response([
            [
                'kind' => KindsEnum::PUBLICATION_INDEX->value,
                'id' => str_repeat('e', 64),
                'tags' => [['d', 'weekly'], ['a', '30041:' . str_repeat('f', 64) . ':other']],
            ],
        ]);

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())->method('findReferencingEvents')->willReturn([]);
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())->method('request')->willReturn($response);

        self::assertNull($this->resolver($repository, $httpClient)->resolve($coordinate));
    }

    private function resolver(EventRepository $repository, HttpClientInterface $httpClient): ChapterParentPublicationResolver
    {
        return new ChapterParentPublicationResolver($repository, $httpClient, 'https://books.example', new NullLogger());
    }

    /** @param list<array<string, mixed>> $events */
    private function response(array $events): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('toArray')->with(false)->willReturn($events);

        return $response;
    }

    private function chapterCoordinate(): string
    {
        return KindsEnum::PUBLICATION_CONTENT->value . ':' . str_repeat('a', 64) . ':intro';
    }

    /** @param list<array{0: string, 1: string}> $tags */
    private function makeEvent(array $tags): Event
    {
        $event = new Event();
        $event->setId(str_repeat('b', 64));
        $event->setKind(KindsEnum::PUBLICATION_INDEX->value);
        $event->setPubkey(str_repeat('c', 64));
        $event->setTags($tags);
        $event->setDTag('weekly');

        return $event;
    }
}
