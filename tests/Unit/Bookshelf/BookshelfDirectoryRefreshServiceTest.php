<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bookshelf;

use App\Bookshelf\BookshelfDirectoryRefreshService;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrClient;
use DecentNewsroom\BookshelfBundle\Service\Bookshelf\BookshelfDirectoryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class BookshelfDirectoryRefreshServiceTest extends TestCase
{
    public function testItProjectsTheDirectoryReturnedByTheLocalRelay(): void
    {
        $pubkey = str_repeat('a', 64);
        $coordinate = '30045:' . $pubkey . ':' . BookshelfDirectoryService::IDENTIFIER;
        $event = (object) [
            'id' => str_repeat('b', 64),
            'kind' => 30045,
            'pubkey' => $pubkey,
            'created_at' => 123,
            'tags' => [['d', BookshelfDirectoryService::IDENTIFIER]],
            'content' => '',
            'sig' => str_repeat('c', 128),
        ];

        $client = $this->createMock(NostrClient::class);
        $client->expects(self::once())
            ->method('getEventsByCoordinates')
            ->with([$coordinate])
            ->willReturn([$coordinate => $event]);

        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::once())
            ->method('projectEventFromNostrEvent')
            ->with($event, 'local');

        $service = new BookshelfDirectoryRefreshService(
            $client,
            $projector,
            $this->createMock(LoggerInterface::class),
        );

        $service->refreshForUser($pubkey);
    }
}
