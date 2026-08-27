<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Api\ChapterFetchController;
use App\Enum\KindsEnum;
use App\Message\FetchEventFromRelaysMessage;
use App\Repository\EventRepository;
use App\Service\Magazine\MagazineStructureService;
use App\Service\Nostr\EventLookupKey;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ChapterFetchControllerTest extends TestCase
{
    public function testFetchChapterDispatchesAsyncNaddrMessageAndReturnsAccepted(): void
    {
        $pubkey = str_repeat('a', 64);
        $coordinate = KindsEnum::PUBLICATION_CONTENT->value . ':' . $pubkey . ':intro';
        $lookupKey = EventLookupKey::forNaddr(KindsEnum::PUBLICATION_CONTENT->value, $pubkey, 'intro');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(function (FetchEventFromRelaysMessage $message) use ($lookupKey, $pubkey): bool {
                return $message->lookupKey === $lookupKey
                    && $message->type === 'naddr'
                    && $message->kind === KindsEnum::PUBLICATION_CONTENT->value
                    && $message->pubkey === $pubkey
                    && $message->identifier === 'intro'
                    && $message->relays === ['wss://relay.example']
                    && $message->mag === 'weekly';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $controller = new ChapterFetchController(
            $bus,
            new MagazineStructureService($this->createMock(EventRepository::class)),
            new NullLogger(),
        );

        $response = $controller->fetchChapter(new Request(content: json_encode([
            'coordinate' => $coordinate,
            'mag' => 'weekly',
            'relayHints' => ['wss://relay.example/', 'not-a-relay'],
        ], JSON_THROW_ON_ERROR)));

        self::assertSame(202, $response->getStatusCode());
        self::assertSame([
            'queued' => true,
            'success' => true,
            'lookupKey' => $lookupKey,
            'lookupTopic' => EventLookupKey::topic($lookupKey),
        ], json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testFetchChapterRejectsNonPublicationContentCoordinate(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $controller = new ChapterFetchController(
            $bus,
            new MagazineStructureService($this->createMock(EventRepository::class)),
            new NullLogger(),
        );

        $response = $controller->fetchChapter(new Request(content: json_encode([
            'coordinate' => '30040:' . str_repeat('a', 64) . ':index',
            'mag' => 'weekly',
        ], JSON_THROW_ON_ERROR)));

        self::assertSame(400, $response->getStatusCode());
    }
}
