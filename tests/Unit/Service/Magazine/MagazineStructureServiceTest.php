<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Magazine;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\Magazine\MagazineStructureService;
use PHPUnit\Framework\TestCase;

final class MagazineStructureServiceTest extends TestCase
{
    public function testParseStructureSeparatesCategoriesChaptersAndFrontArticle(): void
    {
        $magazine = $this->event('magazine', KindsEnum::PUBLICATION_INDEX->value, tags: [
            ['a', '30040:pubkey:essays'],
            ['a', '30041:pubkey:intro', 'wss://relay.example/'],
            ['a', '30023:author:first-front'],
            ['a', '30024:author:draft-front'],
            ['a', 'not-a-coordinate'],
            ['title', 'Ignored title'],
        ]);

        $structure = $this->service()->parseStructure($magazine);

        self::assertSame([['a', '30040:pubkey:essays']], $structure->categoryTags);
        self::assertSame(['30041:pubkey:intro'], $structure->chapterCoordinates);
        self::assertSame(['30041:pubkey:intro' => ['wss://relay.example']], $structure->chapterRelayHints);
        self::assertSame('30023:author:first-front', $structure->frontPageArticleCoordinate);
    }

    public function testBuildCategoryPreviewPayloadUsesCategoryTitleAndFirstArticleCoordinate(): void
    {
        $category = $this->event('category', KindsEnum::PUBLICATION_INDEX->value, tags: [
            ['title', 'Essays'],
            ['a', '30041:pubkey:not-an-article'],
            ['a', '30023:author:essay-one'],
        ]);

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findByCoordinates')
            ->with(['30040:pubkey:essays', '30040:pubkey:missing'])
            ->willReturn([
                '30040:pubkey:essays' => $category,
            ]);

        $payload = $this->service($repository)->buildCategoryPreviewPayload([
            ['a', '30040:pubkey:essays'],
            ['a', '30040:pubkey:missing'],
        ]);

        self::assertSame([
            [
                'categorySlug' => 'essays',
                'categoryTitle' => 'Essays',
                'articleCoordinate' => '30023:author:essay-one',
            ],
            [
                'categorySlug' => 'missing',
                'categoryTitle' => 'missing',
                'articleCoordinate' => null,
            ],
        ], $payload);
    }

    public function testResolveChaptersReturnsFetchedEventsAndMissingPlaceholders(): void
    {
        $chapter = $this->event('chapter', KindsEnum::PUBLICATION_CONTENT->value);

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findByCoordinates')
            ->with(['30041:pubkey:intro', '30041:pubkey:missing', 'broken'])
            ->willReturn([
                '30041:pubkey:intro' => $chapter,
            ]);

        $chapters = $this->service($repository)->resolveChapters([
            '30041:pubkey:intro',
            '30041:pubkey:missing',
            'broken',
        ], [
            '30041:pubkey:intro' => ['wss://relay.example/'],
            '30041:pubkey:missing' => ['wss://relay.two', 'invalid'],
        ]);

        self::assertSame([
            [
                'event' => $chapter,
                'coordinate' => '30041:pubkey:intro',
                'fetched' => true,
                'relayHints' => ['wss://relay.example'],
            ],
            [
                'event' => null,
                'coordinate' => '30041:pubkey:missing',
                'slug' => 'missing',
                'pubkey' => 'pubkey',
                'kind' => KindsEnum::PUBLICATION_CONTENT->value,
                'fetched' => false,
                'relayHints' => ['wss://relay.two'],
            ],
        ], $chapters);
    }

    public function testMissingChapterFetchRequestsReturnsOnlyUnfetchedPublicationContentWithRelayHints(): void
    {
        $magazine = $this->event('magazine', KindsEnum::PUBLICATION_INDEX->value, tags: [
            ['a', '30041:pubkey:intro', 'wss://relay.one/'],
            ['a', '30041:pubkey:missing', 'wss://relay.two'],
            ['a', '30023:author:not-a-chapter', 'wss://relay.three'],
        ]);
        $chapter = $this->event('chapter', KindsEnum::PUBLICATION_CONTENT->value);

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findByCoordinates')
            ->with(['30041:pubkey:intro', '30041:pubkey:missing'])
            ->willReturn([
                '30041:pubkey:intro' => $chapter,
            ]);

        self::assertSame([
            [
                'coordinate' => '30041:pubkey:missing',
                'kind' => KindsEnum::PUBLICATION_CONTENT->value,
                'pubkey' => 'pubkey',
                'identifier' => 'missing',
                'relayHints' => ['wss://relay.two'],
            ],
        ], $this->service($repository)->missingChapterFetchRequests($magazine));
    }

    public function testHydrateEventFromRowAcceptsJsonEncodedTags(): void
    {
        $event = $this->service()->hydrateEventFromRow([
            'id' => 'event-id',
            'event_id' => 'legacy-event-id',
            'kind' => KindsEnum::PUBLICATION_INDEX->value,
            'pubkey' => 'pubkey',
            'content' => 'content',
            'created_at' => 123,
            'tags' => json_encode([['d', 'magazine'], ['title', 'Magazine']]),
            'sig' => 'signature',
        ]);

        self::assertSame('legacy-event-id', $event->getId());
        self::assertSame(KindsEnum::PUBLICATION_INDEX->value, $event->getKind());
        self::assertSame('pubkey', $event->getPubkey());
        self::assertSame('content', $event->getContent());
        self::assertSame(123, $event->getCreatedAt());
        self::assertSame([['d', 'magazine'], ['title', 'Magazine']], $event->getTags());
        self::assertSame('magazine', $event->getDTag());
        self::assertSame('signature', $event->getSig());
    }

    private function service(?EventRepository $repository = null): MagazineStructureService
    {
        return new MagazineStructureService($repository ?? $this->createMock(EventRepository::class));
    }

    /**
     * @param array<int, array<mixed>> $tags
     */
    private function event(string $id, int $kind, string $pubkey = 'pubkey', array $tags = []): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setKind($kind);
        $event->setPubkey($pubkey);
        $event->setContent('');
        $event->setCreatedAt(1);
        $event->setTags($tags);
        $event->setSig('sig');

        return $event;
    }
}