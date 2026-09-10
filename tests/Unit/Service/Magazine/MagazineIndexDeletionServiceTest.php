<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Magazine;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\Magazine\MagazineIndexDeletionService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class MagazineIndexDeletionServiceTest extends TestCase
{
    public function testDeletesOnlyTheSelectedMagazineIndexTree(): void
    {
        $root = '30040:owner:magazine';
        $category = '30040:owner:category';
        $nestedCategory = '30040:other-owner:nested';

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::exactly(3))
            ->method('findByCoordinates')
            ->willReturnOnConsecutiveCalls(
                [$root => $this->event('root', [['a', $category], ['a', '30023:author:article']])],
                [$category => $this->event('category', [['a', $nestedCategory]])],
                [$nestedCategory => $this->event('nested')],
            );

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static fn (callable $callback) => $callback());
        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $parameters): int {
                if (str_starts_with($sql, 'DELETE FROM event')) {
                    self::assertSame(KindsEnum::PUBLICATION_INDEX->value, $parameters['kind_0']);
                    self::assertSame('owner', $parameters['pubkey_0']);
                    self::assertSame('magazine', $parameters['identifier_0']);
                    self::assertSame('other-owner', $parameters['pubkey_2']);

                    return 3;
                }

                self::assertSame('DELETE FROM magazine WHERE slug = :slug AND pubkey = :pubkey', $sql);
                self::assertSame(['slug' => 'magazine', 'pubkey' => 'owner'], $parameters);

                return 1;
            });

        $result = (new MagazineIndexDeletionService($repository, $connection))->deleteRecursively($root);

        self::assertSame(3, $result->deletedIndexes);
        self::assertSame(1, $result->deletedMagazineProjections);
    }

    public function testDoesNotDeleteWhenTheRootIndexIsAbsent(): void
    {
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findByCoordinates')
            ->with(['30040:owner:missing'])
            ->willReturn([]);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');

        $result = (new MagazineIndexDeletionService($repository, $connection))
            ->deleteRecursively('30040:owner:missing');

        self::assertSame(0, $result->deletedIndexes);
        self::assertSame(0, $result->deletedMagazineProjections);
    }

    /**
     * @param array<int, array<mixed>> $tags
     */
    private function event(string $id, array $tags = []): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setKind(KindsEnum::PUBLICATION_INDEX->value);
        $event->setPubkey('owner');
        $event->setContent('');
        $event->setCreatedAt(1);
        $event->setTags($tags);
        $event->setSig('sig');

        return $event;
    }
}
