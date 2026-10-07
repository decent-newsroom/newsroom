<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\IndexArticlesCommand;
use App\Entity\Article;
use App\Enum\IndexStatusEnum;
use App\Service\MutedPubkeysService;
use App\Util\BlockedArticleDomainPolicy;
use App\Util\IndexableArticleChecker;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use FOS\ElasticaBundle\Persister\ObjectPersisterInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class IndexArticlesCommandTest extends TestCase
{
    public function testBlockedDomainIsRejectedAndRemovedWhenQaWasSkipped(): void
    {
        $article = (new Article())->setId(1)->setPubkey(str_repeat('a', 64))
            ->setImage('https://y5.pics/image.png')->setIndexStatus(IndexStatusEnum::TO_BE_INDEXED);
        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn([$article]);
        $builder = $this->createMock(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'andWhere', 'setParameter', 'orderBy', 'setMaxResults'] as $method) {
            $builder->method($method)->willReturnSelf();
        }
        $builder->method('getQuery')->willReturn($query);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQueryBuilder')->willReturn($builder);
        $em->expects(self::once())->method('flush');
        $persister = $this->createMock(ObjectPersisterInterface::class);
        $persister->expects(self::once())->method('deleteManyByIdentifiers')->with(['1']);
        $persister->expects(self::never())->method('replaceMany');
        $mutes = $this->createMock(MutedPubkeysService::class);
        $mutes->method('getMutedPubkeys')->willReturn([]);
        $checker = new IndexableArticleChecker($mutes, new BlockedArticleDomainPolicy());

        $tester = new CommandTester(new IndexArticlesCommand($em, $persister, $checker));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(IndexStatusEnum::DO_NOT_INDEX, $article->getIndexStatus());
    }
}
