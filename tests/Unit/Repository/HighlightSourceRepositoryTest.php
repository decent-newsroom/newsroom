<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\Event;
use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Tests\Unit\Support\HighlightSourceVisibilityFactory;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

final class HighlightSourceRepositoryTest extends TestCase
{
    use HighlightSourceVisibilityFactory;

    public function testDatabaseResolutionMatchesThePreviewLoaderAndRechecksOnEveryRequest(): void
    {
        $entityManager = $this->entityManagerFor([Event::class]);
        $author = str_repeat('a', 64);
        $coordinate = '30040:' . $author . ':Case:Sensitive';
        $exact = $this->event(self::id(101), $author, 1, [], 100);
        $old = $this->event(self::id(102), $author, 30040, [['d', 'Case:Sensitive']], 100);
        $current = $this->event(self::id(103), $author, 30040, [['d', 'Case:Sensitive']], 200);
        $decoy = $this->event(self::id(104), $author, 30040, [['d', 'other'], ['d', 'Case:Sensitive']], 300);
        $decoy->setDTag('Case:Sensitive');
        $highlights = [
            $this->event(self::id(1), $author, 9802, [['e', self::id(101)], ['a', $coordinate]], 1),
            $this->event(self::id(2), $author, 9802, [['e', self::id(999)], ['a', $coordinate]], 2),
            $this->event(self::id(3), $author, 9802, [['a', $coordinate]], 3),
            $this->event(self::id(4), $author, 9802, [['a', '30040:' . $author . ':case:Sensitive']], 4),
            $this->event(self::id(5), $author, 9802, [['a', '30040:' . strtoupper($author) . ':Case:Sensitive']], 5),
        ];
        foreach ([$exact, $old, $current, $decoy, ...$highlights] as $event) {
            $entityManager->persist($event);
        }
        $entityManager->flush();
        $entityManager->clear();

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(Event::class)->willReturn($entityManager);
        $events = new EventRepository($registry);
        $targets = $events->findHighlightSourceTargets([$coordinate], [self::id(101)]);
        self::assertSame(self::id(104), $targets['coordinates'][$coordinate]->getId());
        self::assertSame(self::id(104), $events->findByNaddr(30040, $author, 'Case:Sensitive')->getId());
        self::assertSame(self::id(101), $targets['eventIds'][self::id(101)]->getId());
        self::assertSame('', $targets['coordinates'][$coordinate]->getContent());

        $visibility = $this->makeHighlightVisibility($events);
        $cachedItems = array_map(static fn (Event $event): array => ['id' => $event->getId()], $highlights);
        self::assertSame([self::id(1)], array_column($visibility->filter($cachedItems), 'id'));

        $entityManager->remove($entityManager->find(Event::class, self::id(104)));
        $entityManager->find(Event::class, self::id(103))->setDTag('Case:Sensitive');
        $entityManager->flush();
        $visible = $visibility->filter($cachedItems);
        self::assertSame([self::id(1), self::id(3), self::id(5)], array_column($visible, 'id'));
        self::assertSame($coordinate, $visible[2]['article_ref']);
        self::assertSame(self::id(103), $events->findByNaddr(30040, $author, 'Case:Sensitive')->getId());

        $storedExact = $entityManager->find(Event::class, self::id(101));
        $entityManager->remove($storedExact);
        $entityManager->flush();
        self::assertSame([self::id(3), self::id(5)], array_column($visibility->filter($cachedItems), 'id'));

        $entityManager->close();
    }

    public function testLegacyCoordinateLookupIgnoresMalformedFirstDAndDoesNotInventEmptyIdentifiers(): void
    {
        $entityManager = $this->entityManagerFor([Event::class]);
        $author = str_repeat('a', 64);
        $valid = $this->event(self::id(200), $author, 30040, [['d', '42']], 100);
        $malformed = $this->event(self::id(201), $author, 30040, [['d', 42], ['d', '42']], 200);
        $withoutD = $this->event(self::id(202), $author, 30040, [['title', 'no identifier']], 300);
        foreach ([$valid, $malformed, $withoutD] as $event) {
            $entityManager->persist($event);
        }
        $entityManager->flush();

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(Event::class)->willReturn($entityManager);
        $repository = new EventRepository($registry);
        $coordinate = '30040:' . $author . ':42';
        $emptyCoordinate = '30040:' . $author . ':';
        $targets = $repository->findHighlightSourceTargets([$coordinate, $emptyCoordinate], []);

        self::assertSame(self::id(200), $targets['coordinates'][$coordinate]->getId());
        self::assertArrayNotHasKey($emptyCoordinate, $targets['coordinates']);
        $entityManager->close();
    }

    public function testExactSourceIdsMatchThePreviewLoadersCaseSensitiveDatabaseLookup(): void
    {
        $entityManager = $this->entityManagerFor([Event::class]);
        $author = str_repeat('a', 64);
        $lower = str_repeat('b', 64);
        $upperOnly = str_repeat('C', 64);
        foreach ([
            $this->event($lower, $author, 1, [], 100),
            $this->event(strtoupper($lower), $author, 1, [], 200),
            $this->event($upperOnly, $author, 1, [], 300),
        ] as $event) {
            $entityManager->persist($event);
        }
        $entityManager->flush();

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(Event::class)->willReturn($entityManager);
        $repository = new EventRepository($registry);
        $targets = $repository->findHighlightSourceTargets([], [strtoupper($lower), strtolower($upperOnly)]);

        self::assertSame($lower, $targets['eventIds'][$lower]->getId());
        self::assertArrayNotHasKey(strtolower($upperOnly), $targets['eventIds']);
        self::assertNull($repository->findById(strtolower($upperOnly)));
        $entityManager->close();
    }

    public function testArticleSourceQueriesMatchExactIdsAndDeterministicPreviewRevisions(): void
    {
        $entityManager = $this->entityManagerFor([Article::class]);
        $connection = $entityManager->getConnection();
        $author = str_repeat('a', 64);
        foreach ([
            [str_repeat('d', 64), 'case', 30023],
            [str_repeat('d', 64), 'case', 30023],
            [str_repeat('b', 64), 'edition', 30023],
            [str_repeat('a', 64), 'edition', 30023],
            [str_repeat('f', 64), 'draft', 30024],
            [str_repeat('E', 64), 'uppercase-only', 30023],
        ] as [$eventId, $slug, $kind]) {
            $connection->insert('article', [
                'event_id' => $eventId,
                'pubkey' => $author,
                'slug' => $slug,
                'kind' => $kind,
                'created_at' => '2025-01-01 00:00:00',
                'sig' => str_repeat('f', 128),
                'essayist_exclusive' => false,
            ]);
        }

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(Article::class)->willReturn($entityManager);
        $repository = new ArticleRepository($registry);
        $articles = $repository->findHighlightSourcesByEventIds([
            str_repeat('D', 64),
            str_repeat('d', 64),
            str_repeat('a', 64),
            str_repeat('f', 64),
            str_repeat('e', 64),
            str_repeat('E', 64),
            'not-an-id',
        ]);
        self::assertEqualsCanonicalizing(
            [str_repeat('d', 64), str_repeat('a', 64), str_repeat('f', 64), str_repeat('E', 64)],
            array_keys($articles),
        );
        self::assertSame([1, 2], array_map(static fn (Article $article): ?int => $article->getId(), $articles[str_repeat('d', 64)]));
        self::assertSame(KindsEnum::LONGFORM_DRAFT, $articles[str_repeat('f', 64)][0]->getKind());
        self::assertArrayNotHasKey(str_repeat('e', 64), $articles);
        self::assertArrayNotHasKey(str_repeat('D', 64), $articles);
        $coordinates = $repository->findByCoordinates([['kind' => 30023, 'pubkey' => $author, 'slug' => 'edition']]);
        self::assertSame(str_repeat('a', 64), $coordinates['30023:' . $author . ':edition']->getEventId());
        $entityManager->close();
    }

    public function testReferenceBatchLimitIsEnforced(): void
    {
        $repository = new EventRepository($this->createMock(ManagerRegistry::class));
        $this->expectException(\InvalidArgumentException::class);
        $repository->findHighlightSourceTargets([], array_fill(0, 129, self::id(1)));
    }

    public function testArticleSourceBatchLimitIsEnforced(): void
    {
        $repository = new ArticleRepository($this->createMock(ManagerRegistry::class));
        $this->expectException(\InvalidArgumentException::class);
        $repository->findHighlightSourcesByEventIds(array_fill(0, 129, self::id(1)));
    }

    /** @param list<class-string> $entities */
    private function entityManagerFor(array $entities): EntityManager
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The isolated repository query requires pdo_sqlite.');
        }

        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Entity'], true);
        $config->setNamingStrategy(new \Doctrine\ORM\Mapping\UnderscoreNamingStrategy(CASE_LOWER));
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir());
            $config->setProxyNamespace('Tests\HighlightDoctrineProxies');
        }
        $entityManager = new EntityManager(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            $config,
        );
        (new SchemaTool($entityManager))->createSchema(array_map(
            static fn (string $entity) => $entityManager->getClassMetadata($entity),
            $entities,
        ));

        return $entityManager;
    }

    private function event(string $id, string $author, int $kind, array $tags, int $createdAt): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setPubkey($author);
        $event->setKind($kind);
        $event->setTags($tags);
        $event->setContent('source body');
        $event->setCreatedAt($createdAt);
        $event->setSig('');
        return $event;
    }

    private static function id(int $number): string
    {
        return str_pad(dechex($number), 64, '0', STR_PAD_LEFT);
    }
}
