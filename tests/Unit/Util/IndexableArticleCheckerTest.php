<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util;

use App\Entity\Article;
use App\Enum\IndexStatusEnum;
use App\Service\MutedPubkeysService;
use App\Util\BlockedArticleDomainPolicy;
use App\Util\IndexableArticleChecker;
use PHPUnit\Framework\TestCase;

class IndexableArticleCheckerTest extends TestCase
{
    public function testMutedAuthorIsNeverIndexableEvenWhenQueued(): void
    {
        $mutedService = $this->createMock(MutedPubkeysService::class);
        $mutedService->method('getMutedPubkeys')->willReturn(['abcdef']);
        $checker = new IndexableArticleChecker($mutedService, new BlockedArticleDomainPolicy());

        $article = new Article();
        $article->setPubkey('ABCDEF');
        $article->setIndexStatus(IndexStatusEnum::TO_BE_INDEXED);

        self::assertTrue($checker->isMutedAuthor($article));
        self::assertFalse($checker->isIndexable($article));
    }

    public function testUnmutedAuthorCanBeIndexed(): void
    {
        $mutedService = $this->createMock(MutedPubkeysService::class);
        $mutedService->method('getMutedPubkeys')->willReturn(['abcdef']);
        $checker = new IndexableArticleChecker($mutedService, new BlockedArticleDomainPolicy());

        $article = new Article();
        $article->setPubkey('012345');
        $article->setIndexStatus(IndexStatusEnum::TO_BE_INDEXED);

        self::assertFalse($checker->isMutedAuthor($article));
        self::assertTrue($checker->isIndexable($article));
    }

    public function testDoNotIndexStatusStillBlocksIndexing(): void
    {
        $mutedService = $this->createMock(MutedPubkeysService::class);
        $mutedService->method('getMutedPubkeys')->willReturn([]);
        $checker = new IndexableArticleChecker($mutedService, new BlockedArticleDomainPolicy());

        $article = new Article();
        $article->setPubkey('012345');
        $article->setIndexStatus(IndexStatusEnum::DO_NOT_INDEX);

        self::assertFalse($checker->isIndexable($article));
    }

    public function testBlockedDomainCannotBypassQaThroughPopulation(): void
    {
        $mutedService = $this->createMock(MutedPubkeysService::class);
        $mutedService->method('getMutedPubkeys')->willReturn([]);
        $checker = new IndexableArticleChecker($mutedService, new BlockedArticleDomainPolicy());
        $article = (new Article())
            ->setPubkey(str_repeat('a', 64))
            ->setImage('https://i.postimg.cc/image.jpg')
            ->setIndexStatus(IndexStatusEnum::TO_BE_INDEXED);

        self::assertTrue($checker->hasBlockedDomain($article));
        self::assertFalse($checker->isIndexable($article));
    }
}
