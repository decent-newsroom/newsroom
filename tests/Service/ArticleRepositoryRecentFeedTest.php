<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\ArticleRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ArticleRepositoryRecentFeedTest extends KernelTestCase
{
    private Connection $connection;
    private ArticleRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine')->getConnection();
        $this->connection->beginTransaction();
        $this->connection->executeStatement('CREATE TEMPORARY TABLE article AS SELECT * FROM public.article WHERE FALSE');
        $this->connection->executeStatement('CREATE TEMPORARY TABLE event AS SELECT * FROM public.event WHERE FALSE');
        $this->repository = self::getContainer()->get(ArticleRepository::class);
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        self::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testOnlyMatchingPersistedKindZeroEventMakesArticleEligible(): void
    {
        $author = str_repeat('a', 64);
        $otherAuthor = str_repeat('b', 64);
        $this->insertArticle(1, $author, 'article-a', '2026-09-30 12:00:00');

        self::assertSame([], $this->repository->findLatestForRecentFeed());

        $this->insertEvent('other-metadata', $otherAuthor, 0);
        $this->insertEvent('same-author-follow', $author, 3);
        self::assertSame([], $this->repository->findLatestForRecentFeed());

        $this->insertEvent('matching-metadata', $author, 0);
        self::assertSame(
            ['article-a'],
            array_map(static fn ($article): ?string => $article->getSlug(), $this->repository->findLatestForRecentFeed()),
        );
    }

    public function testIneligibleNewerArticlesDoNotConsumeTheResultLimit(): void
    {
        $eligibleAuthor = str_repeat('c', 64);
        $this->insertArticle(1, str_repeat('d', 64), 'newer-without-metadata', '2026-09-30 12:00:00');
        $this->insertArticle(2, $eligibleAuthor, 'older-with-metadata', '2026-09-30 11:00:00');
        $this->insertEvent('eligible-metadata', $eligibleAuthor, 0);

        self::assertSame(
            ['older-with-metadata'],
            array_map(static fn ($article): ?string => $article->getSlug(), $this->repository->findLatestForRecentFeed(1)),
        );
        self::assertSame([], $this->repository->findLatestForRecentFeed(1, [$eligibleAuthor]));
    }

    private function insertArticle(int $id, string $pubkey, string $slug, string $createdAt): void
    {
        $this->connection->executeStatement(
            'INSERT INTO article (id, kind, pubkey, slug, title, created_at, published_at, essayist_exclusive)
             VALUES (:id, 30023, :pubkey, :slug, :title, :createdAt, :createdAt, FALSE)',
            [
                'id' => $id,
                'pubkey' => $pubkey,
                'slug' => $slug,
                'title' => $slug,
                'createdAt' => $createdAt,
            ],
        );
    }

    private function insertEvent(string $id, string $pubkey, int $kind): void
    {
        $this->connection->executeStatement(
            'INSERT INTO event (id, kind, pubkey, content, created_at, tags, sig)
             VALUES (:id, :kind, :pubkey, :content, :createdAt, :tags, :sig)',
            [
                'id' => $id,
                'kind' => $kind,
                'pubkey' => $pubkey,
                'content' => '{}',
                'createdAt' => 1,
                'tags' => '[]',
                'sig' => '',
            ],
        );
    }
}
