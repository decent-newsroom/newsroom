<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components\Organisms;

use App\Entity\Event;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Twig\Components\Organisms\ArticleFromCoordinate;
use App\Twig\Components\Organisms\ChapterFromCoordinate;
use PHPUnit\Framework\TestCase;

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

        $component = new ChapterFromCoordinate($eventRepository);
        $component->mount(
            '30041:' . str_repeat('a', 64) . ':intro',
            ['wss://publication.example/'],
            'weekly',
        );

        self::assertSame($chapter, $component->chapter);
        self::assertSame(['wss://publication.example/'], $component->relayHints);
        self::assertSame('weekly', $component->mag);
        self::assertNull($component->error);
    }
}
