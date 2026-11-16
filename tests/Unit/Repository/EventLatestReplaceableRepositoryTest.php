<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\Event;
use App\Repository\EventRepository;
use App\Service\UserMuteListService;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class EventLatestReplaceableRepositoryTest extends TestCase
{
    public function testLowerIdWinsTimestampTiesForMetadataAndMuteLists(): void
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
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(Event::class)]);
        $owner = str_repeat('a', 64);
        foreach ([0, 10000] as $kind) {
            foreach ([['f', 200], ['b', 200], ['1', 100]] as [$digit, $time]) {
                $event = new Event();
                $event->setId(sprintf('%064x', $kind + hexdec($digit) + 1));
                $event->setKind($kind);
                $event->setPubkey($owner);
                $event->setCreatedAt($time);
                $event->setTags($kind === 10000 ? [['p', str_repeat($digit, 64)]] : []);
                $event->setContent('');
                $event->setSig('');
                $em->persist($event);
            }
        }
        $em->flush();
        $em->clear();

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(Event::class)->willReturn($em);
        $repository = new EventRepository($registry);
        self::assertSame(sprintf('%064x', 12), $repository->findLatestByPubkeyAndKind($owner, 0)?->getId());
        self::assertSame(sprintf('%064x', 12), $repository->findLatestMetadataByPubkey($owner)?->getId());
        self::assertSame(sprintf('%064x', 10012), $repository->findLatestByPubkeyAndKind($owner, 10000)?->getId());
        self::assertSame([str_repeat('b', 64)], (new UserMuteListService($repository, new NullLogger()))->getMutedPubkeysStrict($owner));
        $em->close();
    }
}
