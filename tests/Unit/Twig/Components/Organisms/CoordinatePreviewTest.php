<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components\Organisms;

use App\Entity\Event;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Service\BooksChapterLookup;
use App\Service\Nostr\NostrNip19Service;
use App\Twig\Components\Organisms\ArticleFromCoordinate;
use App\Twig\Components\Organisms\ChapterFromCoordinate;
use nostriphant\NIP19\Bech32;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CoordinatePreviewTest extends TestCase
{
    public function testArticlePreviewDoesNotQueryArticleRepositoryForChapterCoordinates(): void
    {
        $articleRepository = $this->createMock(ArticleRepository::class);
        $articleRepository->expects(self::never())->method('createQueryBuilder');

        $component = new ArticleFromCoordinate($articleRepository);
        $component->mount('30041:' . str_repeat('a', 64) . ':intro');

        self::assertNull($component->article);
        self::assertNull($component->error);
        self::assertSame('30041', $component->parsedKind);
    }

    public function testChapterPreviewKeepsRelayHintsAndResolvesStoredEvent(): void
    {
        $chapter = new Event();
        $chapter->setId('chapter-event');
        $chapter->setKind(30041);

        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository->expects(self::once())
            ->method('findByNaddr')
            ->with(30041, str_repeat('a', 64), 'intro')
            ->willReturn($chapter);

        $booksLookup = $this->createMock(BooksChapterLookup::class);
        $booksLookup->expects(self::never())->method('find');
        $component = new ChapterFromCoordinate($eventRepository, $booksLookup, new NostrNip19Service(), new NullLogger());
        $component->mount(
            '30041:' . str_repeat('a', 64) . ':intro',
            ['wss://publication.example/'],
            'weekly',
        );

        self::assertSame($chapter, $component->chapter);
        self::assertSame(['wss://publication.example/'], $component->relayHints);
        self::assertSame('weekly', $component->mag);
        self::assertTrue($component->stored);
        self::assertFalse($component->useSharedPreview);
        self::assertNull($component->previewBech);
        self::assertNull($component->error);
    }

    public function testChapterPreviewUsesSharedPreviewForStoredNonMagazineChapter(): void
    {
        $pubkey = str_repeat('d', 64);
        $chapter = new Event();
        $chapter->setId(str_repeat('e', 64));
        $chapter->setKind(30041);
        $chapter->setPubkey($pubkey);
        $chapter->setDTag('intro');

        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository->expects(self::once())->method('findByNaddr')
            ->with(30041, $pubkey, 'intro')->willReturn($chapter);
        $booksLookup = $this->createMock(BooksChapterLookup::class);
        $booksLookup->expects(self::never())->method('find');

        $component = new ChapterFromCoordinate($eventRepository, $booksLookup, new NostrNip19Service(), new NullLogger());
        $component->mount('30041:' . $pubkey . ':intro', ['wss://publication.example/']);

        self::assertTrue($component->stored);
        self::assertTrue($component->useSharedPreview);
        self::assertNotNull($component->previewBech);
        $decoded = (new Bech32($component->previewBech))->data;
        self::assertSame(30041, $decoded->kind);
        self::assertSame($pubkey, $decoded->pubkey);
        self::assertSame('intro', $decoded->identifier);
        self::assertSame(['wss://publication.example/'], $decoded->relays);
    }

    public function testChapterPreviewUsesBooksApiWithoutLocalCopy(): void
    {
        $pubkey = str_repeat('b', 64);
        $chapter = new Event();
        $chapter->setId(str_repeat('c', 64));
        $chapter->setKind(30041);
        $chapter->setPubkey($pubkey);
        $chapter->setDTag('intro');

        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository->expects(self::once())->method('findByNaddr')
            ->with(30041, $pubkey, 'intro')->willReturn(null);
        $booksLookup = $this->createMock(BooksChapterLookup::class);
        $booksLookup->expects(self::once())->method('find')
            ->with($pubkey, 'intro')->willReturn($chapter);

        $component = new ChapterFromCoordinate($eventRepository, $booksLookup, new NostrNip19Service(), new NullLogger());
        $component->mount('30041:' . $pubkey . ':intro');

        self::assertSame($chapter, $component->chapter);
        self::assertFalse($component->stored);
        self::assertFalse($component->useSharedPreview);
        self::assertNull($component->previewBech);
        self::assertNull($component->error);
    }
}
