<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

final class ArticleWorkspaceEntitlementRepositoryTest extends TestCase
{
    public function testEntitlementLookupReturnsOnlyAccessMetadataWithoutArticleBodyHtml(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The isolated Article access query requires pdo_sqlite.');
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
        (new SchemaTool($entityManager))->createSchema([$entityManager->getClassMetadata(Article::class)]);
        $entityManager->getConnection()->insert('article', [
            'event_id' => str_repeat('a', 64),
            'slug' => 'story',
            'kind' => KindsEnum::LONGFORM->value,
            'pubkey' => str_repeat('b', 64),
            'created_at' => '2025-01-01 00:00:00',
            'sig' => str_repeat('f', 128),
            'raw' => json_encode(['tags' => [['d', 'story']]], JSON_THROW_ON_ERROR),
            'content' => 'must-not-hydrate-body',
            'processed_html' => '<script>must-not-hydrate-html</script>',
            'essayist_exclusive' => true,
        ]);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(Article::class)->willReturn($entityManager);
        $repository = new ArticleRepository($registry);

        $article = $repository->findWorkspaceEntitlementByEventId(str_repeat('a', 64));

        self::assertInstanceOf(Article::class, $article);
        self::assertSame('story', $article->getSlug());
        self::assertNull($article->getContent());
        self::assertNull($article->getProcessedHtml());
        self::assertTrue($article->isEssayistExclusive());
        $entityManager->close();
    }
}
