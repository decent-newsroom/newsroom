<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components\Molecules;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Twig\Components\Molecules\ChapterCard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ChapterCardTest extends TestCase
{
    public function testChapterUsesTagMetadataAndChapterRoute(): void
    {
        $chapter = $this->chapter([
            ['d', 'intro'],
            ['title', 'An introduction'],
            ['summary', 'A chapter summary'],
        ], 'A longer chapter body.');
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->expects(self::once())
            ->method('generate')
            ->with('chapter', self::callback(static fn (array $parameters): bool => str_starts_with($parameters['naddr'], 'naddr1')))
            ->willReturn('/chapter/intro');
        $component = new ChapterCard($urls);

        $component->mount($chapter);

        self::assertSame('intro', $component->slug);
        self::assertSame('An introduction', $component->title);
        self::assertSame('A chapter summary', $component->summary);
        self::assertSame($chapter->getPubkey(), $component->authorPubkey);
        self::assertSame(123, $component->createdAt);
        self::assertSame('/chapter/intro', $component->link);
    }

    public function testChapterFallsBackToSlugAndPlainAsciiDocExcerpt(): void
    {
        $chapter = $this->chapter(
            [['d', 'intro']],
            "= Intro\n\nRead *this* section.\nimage::https://example.test/cover.png[]",
        );
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/chapter/intro');
        $component = new ChapterCard($urls);

        $component->mount($chapter);

        self::assertSame('intro', $component->title);
        self::assertSame('Intro Read this section.', $component->summary);
        self::assertStringNotContainsString('example.test', $component->summary);
    }

    /** @param list<list<string>> $tags */
    private function chapter(array $tags, string $content): Event
    {
        $event = new Event();
        $event->setId(str_repeat('b', 64));
        $event->setKind(KindsEnum::PUBLICATION_CONTENT->value);
        $event->setPubkey(str_repeat('a', 64));
        $event->setCreatedAt(123);
        $event->setTags($tags);
        $event->setContent($content);
        $event->setSig(str_repeat('c', 128));

        return $event;
    }
}
