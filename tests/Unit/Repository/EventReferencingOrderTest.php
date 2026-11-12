<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\Event;
use App\Repository\EventRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

final class EventReferencingOrderTest extends TestCase
{
    /** @dataProvider orderingCases */
    public function testCandidateOrderingPrecedesLimit(bool $newestFirst, string $order): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeQuery')->with(
            self::callback(static fn (string $sql): bool => str_ends_with($sql, $order . ' LIMIT :limit')),
            ['jsonTag' => '[["a","30040:author:book"]]', 'kind_0' => 30040, 'limit' => 50],
        )->willReturn($result);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getClassMetadata')->with(Event::class)->willReturn(new ClassMetadata(Event::class));
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(Event::class)->willReturn($em);
        $repository = new EventRepository($registry);

        self::assertSame([], $newestFirst
            ? $repository->findReferencingEvents('a', '30040:author:book', [30040], 50, true)
            : $repository->findReferencingEvents('a', '30040:author:book', [30040], 50));
    }

    public static function orderingCases(): iterable
    {
        yield 'existing graph callers keep oldest first' => [false, 'ORDER BY e.created_at ASC'];
        yield 'parent discovery prefers recent indexes with stable ties' => [true, 'ORDER BY e.created_at DESC, e.id ASC'];
    }
}
