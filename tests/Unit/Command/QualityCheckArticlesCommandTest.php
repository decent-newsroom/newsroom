<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\QualityCheckArticlesCommand;
use App\Entity\Article;
use App\Entity\User;
use App\Enum\IndexStatusEnum;
use App\Enum\KindsEnum;
use App\Enum\RolesEnum;
use App\Repository\ArticleRepository;
use App\Repository\UserEntityRepository;
use App\Service\MutedPubkeysService;
use App\Util\BlockedArticleDomainPolicy;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Elastica\Index;
use Elastica\Response;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class QualityCheckArticlesCommandTest extends TestCase
{
    /** @dataProvider userProvider */
    public function testHistoricalMatchMutesAuthorBeforeApprovingArticles(bool $existing): void
    {
        $hex = str_repeat('a', 64);
        $npub = PublicKey::fromHex($hex)->toBech32();
        $matched = (new Article())->setId(12)->setPubkey($hex)
            ->setContent('![image](https://i.postimg.cc/image.png)')
            ->setIndexStatus(IndexStatusEnum::INDEXED);
        $user = $existing ? new User() : null;
        if ($user !== null) {
            $user->setNpub($npub);
            $user->addRole(RolesEnum::WRITER->value);
        }
        $users = $this->createMock(UserEntityRepository::class);
        $users->method('getMutedPubkeys')->willReturn([]);
        $users->expects(self::once())->method('findOneBy')->with(['npub' => $npub])->willReturn($user);

        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn([12]);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeQuery')
            ->with(
                self::callback(static fn (string $sql): bool => str_contains($sql, 'raw::text')
                    && !str_contains($sql, 'index_status') && str_contains($sql, 'kind != :draft')),
                self::callback(static fn (array $parameters): bool => $parameters['draft'] === KindsEnum::LONGFORM_DRAFT->value
                    && $parameters['lastId'] === 0 && $parameters['domain2'] === '%i.postimg.cc%'),
            )->willReturn($result);

        $articles = $this->createMock(ArticleRepository::class);
        $articles->expects(self::exactly(2))->method('findBy')->willReturnMap([
            [['id' => [12]], null, null, null, [$matched]],
            [['indexStatus' => IndexStatusEnum::NOT_INDEXED], ['id' => 'ASC'], 100, null, []],
        ]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getRepository')->with(Article::class)->willReturn($articles);
        $created = null;
        if ($existing) {
            $em->expects(self::never())->method('persist');
        } else {
            $em->expects(self::once())->method('persist')->willReturnCallback(
                static function (User $newUser) use (&$created): void {
                    $created = $newUser;
                },
            );
        }
        $query = $this->createMock(Query::class);
        $query->expects(self::once())->method('execute')->willReturn(2);
        $builder = $this->createMock(QueryBuilder::class);
        foreach (['update', 'set', 'where', 'andWhere'] as $method) {
            $builder->method($method)->willReturnSelf();
        }
        $builder->expects(self::exactly(2))->method('setParameter')->willReturnCallback(
            function (string $name, mixed $value) use ($hex, $builder): QueryBuilder {
                if ($name === 'pubkeys') {
                    self::assertSame([$hex], $value);
                } else {
                    self::assertSame(IndexStatusEnum::DO_NOT_INDEX, $value);
                }
                return $builder;
            },
        );
        $builder->method('getQuery')->willReturn($query);
        $em->method('createQueryBuilder')->willReturn($builder);
        $em->expects(self::exactly(2))->method('flush');
        $cache = $this->createMock(MutedPubkeysService::class);
        $cache->expects(self::once())->method('invalidateCache');
        $response = new Response(['deleted' => 2]);
        $index = $this->createMock(Index::class);
        $index->expects(self::once())->method('deleteByQuery')
            ->with(self::callback(static fn ($query): bool => $query->toArray() === ['terms' => ['pubkey' => [$hex]]]), ['refresh' => true])
            ->willReturn($response);

        $tester = new CommandTester(new QualityCheckArticlesCommand(
            $em, $users, $index, true, new BlockedArticleDomainPolicy(), $cache,
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $muted = $user ?? $created;
        self::assertSame($npub, $muted->getNpub());
        self::assertContains(RolesEnum::MUTED->value, $muted->getRoles());
        if ($existing) {
            self::assertContains(RolesEnum::WRITER->value, $muted->getRoles());
        }
        self::assertStringContainsString('contains blocked domain i.postimg.cc', $tester->getDisplay());
    }

    public static function userProvider(): array
    {
        return ['existing user keeps roles' => [true], 'unknown author is persisted' => [false]];
    }

    public function testSafeUrlCandidateDoesNotMuteAndNormalQaStillApproves(): void
    {
        $article = (new Article())->setId(1)->setPubkey(str_repeat('b', 64))
            ->setTitle('A normal article')->setSlug('normal')
            ->setContent('This is an ordinary article with more than twelve words and a safe link https://example.com/y5.pics/path');
        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn([1]);
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($result);
        $articles = $this->createMock(ArticleRepository::class);
        $articles->method('findBy')->willReturn([$article]);
        $users = $this->createMock(UserEntityRepository::class);
        $users->method('getMutedPubkeys')->willReturn([]);
        $users->expects(self::never())->method('findOneBy');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getRepository')->willReturn($articles);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('createQueryBuilder');
        $cache = $this->createMock(MutedPubkeysService::class);
        $cache->expects(self::never())->method('invalidateCache');
        $index = $this->createMock(Index::class);
        $index->expects(self::never())->method('deleteByQuery');

        $tester = new CommandTester(new QualityCheckArticlesCommand(
            $em, $users, $index, false, new BlockedArticleDomainPolicy(), $cache,
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(IndexStatusEnum::TO_BE_INDEXED, $article->getIndexStatus());
    }

    public function testFailedSearchCleanupStopsQaBeforeChangingArticleStatuses(): void
    {
        $users = $this->createMock(UserEntityRepository::class);
        $users->method('getMutedPubkeys')->willReturn([str_repeat('a', 64)]);
        $result = $this->createMock(Result::class);
        $result->method('fetchFirstColumn')->willReturn([]);
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn($result);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->expects(self::never())->method('createQueryBuilder');
        $em->expects(self::never())->method('getRepository');
        $em->expects(self::never())->method('flush');
        $index = $this->createMock(Index::class);
        $index->method('deleteByQuery')->willReturn(new Response(['timed_out' => true]));

        $tester = new CommandTester(new QualityCheckArticlesCommand(
            $em, $users, $index, true, new BlockedArticleDomainPolicy(), $this->createMock(MutedPubkeysService::class),
        ));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('Error removing muted-user articles', $tester->getDisplay());
    }
}
