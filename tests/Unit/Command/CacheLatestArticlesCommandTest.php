<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\CacheLatestArticlesCommand;
use App\Dto\UserMetadata;
use App\Entity\Article;
use App\Entity\Event;
use App\ReadModel\RedisView\RedisBaseObject;
use App\ReadModel\RedisView\RedisViewFactory;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Service\Cache\RedisViewStore;
use App\Service\LatestArticles\LatestArticlesExclusionPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CacheLatestArticlesCommandTest extends TestCase
{
    public function testOnlyAuthorsWithPersistedMetadataAreWrittenToLatestView(): void
    {
        $qualifiedPubkey = str_repeat('a', 64);
        $missingPubkey = str_repeat('b', 64);

        $qualifiedArticle = (new Article())->setPubkey($qualifiedPubkey)->setSlug('qualified');
        $missingArticle = (new Article())->setPubkey($missingPubkey)->setSlug('missing');

        $articles = $this->createMock(ArticleRepository::class);
        $articles->expects(self::once())
            ->method('findLatestForRecentFeed')
            ->with(500, [])
            ->willReturn([$qualifiedArticle, $missingArticle]);

        $metadataEvent = new Event();
        $metadataEvent->setId('metadata-event');
        $metadataEvent->setPubkey($qualifiedPubkey);
        $metadataEvent->setKind(0);
        $metadataEvent->setContent('{"name":"Qualified author"}');

        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())
            ->method('findLatestMetadataByPubkeys')
            ->with([$qualifiedPubkey, $missingPubkey])
            ->willReturn([$qualifiedPubkey => $metadataEvent]);

        $policy = $this->createMock(LatestArticlesExclusionPolicy::class);
        $policy->method('getAllExcludedPubkeys')->willReturn([]);
        $policy->method('shouldExclude')->willReturn(false);

        $baseObject = new RedisBaseObject();
        $factory = $this->createMock(RedisViewFactory::class);
        $factory->expects(self::once())
            ->method('articleBaseObject')
            ->with(
                $qualifiedArticle,
                self::callback(static fn ($metadata): bool => $metadata instanceof UserMetadata
                    && $metadata->name === 'Qualified author'),
            )
            ->willReturn($baseObject);

        $store = $this->createMock(RedisViewStore::class);
        $store->expects(self::once())
            ->method('storeLatestArticles')
            ->with([$baseObject]);

        $command = new CacheLatestArticlesCommand($articles, $events, $store, $factory, $policy);
        self::assertSame(Command::SUCCESS, (new CommandTester($command))->execute([]));
    }
}
