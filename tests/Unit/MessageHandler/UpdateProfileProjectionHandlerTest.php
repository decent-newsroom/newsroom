<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\MessageHandler\UpdateProfileProjectionHandler;
use App\Repository\EventRepository;
use App\Repository\UserEntityRepository;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\UserRelayListService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

class UpdateProfileProjectionHandlerTest extends TestCase
{
    public function testParseUserMetadataCollectsAllWebsiteTags(): void
    {
        $handler = new UpdateProfileProjectionHandler(
            $this->createMock(NostrClient::class),
            $this->createMock(CacheItemPoolInterface::class),
            $this->createMock(UserEntityRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(UserRelayListService::class),
            $this->createMock(GenericEventProjector::class),
            $this->createMock(EventRepository::class),
        );

        $rawEvent = new \stdClass();
        $rawEvent->content = json_encode(['name' => 'Alice']);
        $rawEvent->tags = [
            ['website', 'https://example.com'],
            ['website', 'https://blog.example.com'],
            ['website', 'https://example.com'],
        ];

        $method = new \ReflectionMethod($handler, 'parseUserMetadata');
        $method->setAccessible(true);

        $metadata = $method->invoke($handler, $rawEvent, str_repeat('a', 64));

        $this->assertSame(['https://example.com', 'https://blog.example.com'], $metadata->website);
    }
}

