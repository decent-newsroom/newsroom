<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\DeletedEvent;
use App\Entity\Event;
use App\Entity\Magazine;
use App\Enum\KindsEnum;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\MagazineRepository;
use App\Service\EventDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class EventDeletionServiceTest extends TestCase
{
    public function testFlushesAndClearsLargeDeletionRequestsInChunks(): void
    {
        $deletionRequest = new Event();
        $deletionRequest->setId('delete-request');
        $deletionRequest->setKind(KindsEnum::DELETION_REQUEST->value);
        $deletionRequest->setPubkey('pubkey-1');
        $deletionRequest->setCreatedAt(123);
        $deletionRequest->setContent('cleanup');
        $deletionRequest->setTags(array_map(
            static fn (int $i): array => ['e', sprintf('event-%02d', $i)],
            range(1, 51),
        ));

        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository->expects($this->exactly(51))
            ->method('find')
            ->willReturn(null);

        $deletedEventRepository = $this->createMock(DeletedEventRepository::class);
        $deletedEventRepository->expects($this->exactly(51))
            ->method('findByTargetRef')
            ->willReturn(null);

        $magazineRepository = $this->createMock(MagazineRepository::class);
        $magazineRepository->expects($this->never())->method('findBySlug');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);
        $entityManager->method('getRepository')->willReturnCallback(
            static function (string $className) use ($eventRepository, $deletedEventRepository, $magazineRepository) {
                return match ($className) {
                    Event::class => $eventRepository,
                    DeletedEvent::class => $deletedEventRepository,
                    Magazine::class => $magazineRepository,
                    default => throw new \InvalidArgumentException('Unexpected repository: ' . $className),
                };
            }
        );
        $entityManager->expects($this->exactly(51))
            ->method('persist')
            ->with($this->isInstanceOf(DeletedEvent::class));
        $entityManager->expects($this->exactly(2))->method('flush');
        $entityManager->expects($this->exactly(2))->method('clear');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(DeletedEvent::class)->willReturn($entityManager);
        $registry->expects($this->never())->method('resetManager');

        $service = new EventDeletionService($registry, new NullLogger());

        $result = $service->processDeletionRequest($deletionRequest);

        self::assertSame([
            'processed' => 0,
            'suppressed' => 51,
            'skipped' => 0,
        ], $result);
    }

    public function testContinuesAfterSingleTargetFailureWithinSameRequest(): void
    {
        $deletionRequest = new Event();
        $deletionRequest->setId('delete-request');
        $deletionRequest->setKind(KindsEnum::DELETION_REQUEST->value);
        $deletionRequest->setPubkey('pubkey-1');
        $deletionRequest->setCreatedAt(456);
        $deletionRequest->setTags([
            ['e', 'broken-event'],
            ['e', 'ok-event'],
        ]);

        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository->expects($this->exactly(2))
            ->method('find')
            ->willReturn(null);

        $deletedEventRepository = $this->createMock(DeletedEventRepository::class);
        $deletedEventRepository->expects($this->exactly(2))
            ->method('findByTargetRef')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RuntimeException('broken tombstone write')),
                null,
            );

        $magazineRepository = $this->createMock(MagazineRepository::class);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);
        $entityManager->method('getRepository')->willReturnCallback(
            static function (string $className) use ($eventRepository, $deletedEventRepository, $magazineRepository) {
                return match ($className) {
                    Event::class => $eventRepository,
                    DeletedEvent::class => $deletedEventRepository,
                    Magazine::class => $magazineRepository,
                    default => throw new \InvalidArgumentException('Unexpected repository: ' . $className),
                };
            }
        );
        $entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(DeletedEvent::class));
        $entityManager->expects($this->once())->method('flush');
        $entityManager->expects($this->exactly(2))->method('clear');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(DeletedEvent::class)->willReturn($entityManager);
        $registry->expects($this->never())->method('resetManager');

        $service = new EventDeletionService($registry, new NullLogger());

        $result = $service->processDeletionRequest($deletionRequest);

        self::assertSame([
            'processed' => 0,
            'suppressed' => 1,
            'skipped' => 1,
        ], $result);
    }

    public function testDuplicateTargetRefsInSingleRequestDoNotInsertDuplicateTombstones(): void
    {
        $deletionRequest = new Event();
        $deletionRequest->setId('delete-request');
        $deletionRequest->setKind(KindsEnum::DELETION_REQUEST->value);
        $deletionRequest->setPubkey('pubkey-1');
        $deletionRequest->setCreatedAt(789);
        $deletionRequest->setTags([
            ['e', 'same-event-id'],
            ['e', 'same-event-id'],
        ]);

        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository->expects($this->exactly(2))
            ->method('find')
            ->willReturn(null);

        $deletedEventRepository = $this->createMock(DeletedEventRepository::class);
        $deletedEventRepository->expects($this->once())
            ->method('findByTargetRef')
            ->with('same-event-id')
            ->willReturn(null);

        $magazineRepository = $this->createMock(MagazineRepository::class);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturn(true);
        $entityManager->method('getRepository')->willReturnCallback(
            static function (string $className) use ($eventRepository, $deletedEventRepository, $magazineRepository) {
                return match ($className) {
                    Event::class => $eventRepository,
                    DeletedEvent::class => $deletedEventRepository,
                    Magazine::class => $magazineRepository,
                    default => throw new \InvalidArgumentException('Unexpected repository: ' . $className),
                };
            }
        );
        $entityManager->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(DeletedEvent::class));
        $entityManager->expects($this->once())->method('flush');
        $entityManager->expects($this->once())->method('clear');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(DeletedEvent::class)->willReturn($entityManager);
        $registry->expects($this->never())->method('resetManager');

        $service = new EventDeletionService($registry, new NullLogger());

        $result = $service->processDeletionRequest($deletionRequest);

        self::assertSame([
            'processed' => 0,
            'suppressed' => 2,
            'skipped' => 0,
        ], $result);
    }
}

