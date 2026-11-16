<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\DeletedEvent;
use App\Entity\Event;
use App\Repository\DeletedEventRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

final class DeletedEventRepositoryBatchTest extends TestCase
{
    /** @dataProvider coordinateCases */
    public function testBatchMatchesSingleEventChecksForCaseSensitiveIdentifiers(bool $lowercaseTombstone): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('Requires pdo_sqlite.');
        }
        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Entity'], true);
        $config->setNamingStrategy(new \Doctrine\ORM\Mapping\UnderscoreNamingStrategy(CASE_LOWER));
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir());
            $config->setProxyNamespace('Tests\DoctrineProxies');
        }
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(DeletedEvent::class)]);
        $author = str_repeat('a', 64);
        $this->persistCoordinateTombstone($em, $author, 'Abc', 200);
        if ($lowercaseTombstone) {
            $this->persistCoordinateTombstone($em, $author, 'abc', 100);
        }
        $this->persistCoordinateTombstone($em, $author, 'Abc:Two', 150);
        $this->persistEventTombstone($em, sprintf('%064x', 9), $author);
        $this->persistEventTombstone($em, sprintf('%064x', 11), str_repeat('b', 64));
        $em->flush();
        $em->clear();

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(DeletedEvent::class)->willReturn($em);
        $repository = new DeletedEventRepository($registry);
        $events = [];
        $expected = [];
        foreach ([
            ['Abc', 199, true], ['Abc', 200, true], ['Abc', 201, false],
            ['abc', 99, $lowercaseTombstone], ['abc', 100, $lowercaseTombstone], ['abc', 101, false],
            ['Abc:Two', 150, true], ['abc:two', 100, false],
            ['pinned', 500, true], ['pinned', 500, false], ['pinned', 500, false],
        ] as $index => [$identifier, $createdAt, $suppressed]) {
            $event = new Event();
            $event->setId(sprintf('%064x', $index + 1));
            $event->setKind(30004);
            $event->setPubkey($author);
            $event->setCreatedAt($createdAt);
            $event->setTags([['d', $identifier]]);
            $events[] = $event;
            self::assertSame(
                $suppressed,
                $repository->isSuppressed($event->getId(), 30004, $author, $identifier, $createdAt),
                'Single-event decision for ' . $identifier . ' at ' . $createdAt,
            );
            if ($suppressed) {
                $expected[] = $event->getId();
            }
        }

        self::assertSame($expected, $repository->findSuppressedEventIds($events));
        $em->close();
    }

    public static function coordinateCases(): iterable
    {
        yield 'only mixed-case coordinate deleted' => [false];
        yield 'both coordinates deleted with different cutoffs' => [true];
    }

    private function persistCoordinateTombstone(EntityManager $em, string $author, string $identifier, int $cutoff): void
    {
        $tombstone = (new DeletedEvent())
            ->setTargetRef('30004:' . $author . ':' . $identifier)
            ->setRefType(DeletedEvent::REF_COORDINATE)
            ->setPubkey($author)
            ->setKind(30004)
            ->setDeletionEventId(str_repeat('f', 64))
            ->setDeletionCreatedAt($cutoff);
        $em->persist($tombstone);
    }

    private function persistEventTombstone(EntityManager $em, string $eventId, string $author): void
    {
        $tombstone = (new DeletedEvent())
            ->setTargetRef($eventId)
            ->setRefType(DeletedEvent::REF_EVENT_ID)
            ->setPubkey($author)
            ->setDeletionEventId(str_repeat('f', 64))
            ->setDeletionCreatedAt(1);
        $em->persist($tombstone);
    }
}
