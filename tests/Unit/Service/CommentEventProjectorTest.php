<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Event;
use App\Repository\EventRepository;
use App\Service\CommentEventProjector;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CommentEventProjectorTest extends TestCase
{
    public function testProjectEventsPersistsGenericReferencedKinds(): void
    {
        $repository = $this->createMock(EventRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $repository->expects($this->exactly(2))
            ->method('findById')
            ->willReturn(null);

        $persistedKinds = [];
        $entityManager->expects($this->exactly(2))
            ->method('persist')
            ->with($this->callback(function (Event $event) use (&$persistedKinds): bool {
                $persistedKinds[] = $event->getKind();
                return true;
            }));

        $entityManager->expects($this->once())
            ->method('flush');

        $projector = new CommentEventProjector($repository, $entityManager, $logger);

        $count = $projector->projectEvents([
            (object) [
                'id' => str_repeat('1', 64),
                'kind' => 1,
                'pubkey' => str_repeat('2', 64),
                'created_at' => 100,
                'tags' => [['a', '30023:' . str_repeat('3', 64) . ':article-slug']],
                'content' => 'kind 1 reply',
                'sig' => str_repeat('4', 128),
            ],
            (object) [
                'id' => str_repeat('5', 64),
                'kind' => 1111,
                'pubkey' => str_repeat('6', 64),
                'created_at' => 101,
                'tags' => [['A', '30023:' . str_repeat('3', 64) . ':article-slug']],
                'content' => 'NIP-22 comment',
                'sig' => str_repeat('7', 128),
            ],
        ]);

        self::assertSame(2, $count);
        self::assertSame([1, 1111], $persistedKinds);
    }
}