<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Event;
use App\Repository\EventRepository;
use App\Repository\FollowPackSourceRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\FollowPackService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class FollowPackServiceTest extends TestCase
{
    public function testMalformedMembersDoNotPreventValidMembersFromBeingUsed(): void
    {
        $curator = str_repeat('a', 64);
        $member = str_repeat('b', 64);
        $event = new Event();
        $event->setTags([['d', 'writers'], ['p', ['invalid']], ['p', 'not-a-key'], ['p', $member], ['p', strtoupper($member)]]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findBy')->willReturn([$event]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(Event::class)->willReturn($events);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('warning');
        $service = new FollowPackService(
            $this->createMock(FollowPackSourceRepository::class),
            $em,
            $this->createMock(RedisCacheService::class),
            $logger,
        );

        self::assertSame([$member], $service->getPubkeysFromCoordinate('39089:' . $curator . ':writers'));
    }
}
