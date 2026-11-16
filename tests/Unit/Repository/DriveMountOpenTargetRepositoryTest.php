<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\Event;
use App\Repository\EventRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

final class DriveMountOpenTargetRepositoryTest extends TestCase
{
    public function testUnpinnedAddressSelectsCurrentRevisionWhilePinRemainsExact(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The isolated drive target query requires pdo_sqlite.');
        }

        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Entity'], true);
        $config->setNamingStrategy(new \Doctrine\ORM\Mapping\UnderscoreNamingStrategy(CASE_LOWER));
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir());
            $config->setProxyNamespace('Tests\DoctrineProxies');
        }
        $entityManager = new EntityManager(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            $config,
        );
        (new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(Event::class)]);
        $author = str_repeat('a', 64);
        $old = $this->event(str_repeat('b', 64), $author, 100, 'chapter');
        $current = $this->event(str_repeat('c', 64), $author, 200, 'chapter');
        foreach ([$old, $current] as $event) {
            $entityManager->persist($event);
        }
        $entityManager->flush();
        $entityManager->clear();

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(Event::class)->willReturn($entityManager);
        $repository = new EventRepository($registry);
        $currentResults = $repository->findWorkspaceDriveOpenTargets(
            ['30041:' . $author . ':chapter'],
            [],
        );
        $pinResults = $repository->findWorkspaceDriveOpenTargets([], [str_repeat('b', 64)]);

        self::assertSame(str_repeat('c', 64), $currentResults['coordinates']['30041:' . $author . ':chapter']->getId());
        self::assertSame(str_repeat('b', 64), $pinResults['eventIds'][str_repeat('b', 64)]->getId());
        self::assertSame('Chapter body', $currentResults['coordinates']['30041:' . $author . ':chapter']->getContent());
        $entityManager->close();
    }

    private function event(string $id, string $author, int $createdAt, string $identifier): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setPubkey($author);
        $event->setKind(30041);
        $event->setCreatedAt($createdAt);
        $event->setTags([['d', $identifier], ['title', 'Chapter']]);
        $event->setContent('Chapter body');
        $event->setSig(str_repeat('f', 128));

        return $event;
    }
}
