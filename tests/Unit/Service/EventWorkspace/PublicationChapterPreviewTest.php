<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\EventWorkspace;

use App\Entity\Event;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\PublicationChapterPreview;
use App\Service\EventWorkspace\PublicationTocSourceAccess;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\ContentAuthorAccessPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PublicationChapterPreviewTest extends TestCase
{
    private const SOURCE_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CHAPTER_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const AUTHOR = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const MUTED = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';
    private const COORDINATE = '30041:' . self::AUTHOR . ':chapter-one';

    public function testAuthorizedPinnedChapterReturnsTagMetadataAndBody(): void
    {
        $source = $this->source([['a', self::COORDINATE, '', self::CHAPTER_ID]]);
        $metadata = $this->chapter('', [['d', 'chapter-one'], ['title', 'First chapter'], ['summary', 'A short summary']]);
        $chapter = $this->chapter("== Opening\n\nThe chapter body.", [
            ['d', 'chapter-one'], ['title', 'First chapter'], ['summary', 'A short summary'],
        ]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findWorkspacePreviewTargets')
            ->with([], [self::CHAPTER_ID])
            ->willReturn(['coordinates' => [], 'eventIds' => [self::CHAPTER_ID => $metadata]]);
        $events->expects(self::once())->method('findById')->with(self::CHAPTER_ID)->willReturn($chapter);
        $sourceAccess = $this->allowedTargetAccess();

        $result = $this->preview($events, $sourceAccess)->fromAuthorizedIndexOccurrence(
            $source, 1, self::CHAPTER_ID, self::COORDINATE, [],
        );

        self::assertSame(self::CHAPTER_ID, $result['eventId']);
        self::assertSame('First chapter', $result['title']);
        self::assertSame('A short summary', $result['summary']);
        self::assertSame("== Opening\n\nThe chapter body.", $result['content']);
        self::assertSame('chapter-one', $result['identifier']);
        self::assertFalse($result['sensitive']);
    }

    public function testUnpinnedAddressResolvesOnlyItsCurrentLocalChapter(): void
    {
        $source = $this->source([['a', self::COORDINATE]]);
        $metadata = $this->chapter('', [['d', 'chapter-one'], ['title', 'Latest chapter']]);
        $chapter = $this->chapter('Latest body', [['d', 'chapter-one'], ['title', 'Latest chapter']]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findWorkspacePreviewTargets')
            ->with([self::COORDINATE], [])
            ->willReturn(['coordinates' => [self::COORDINATE => $metadata], 'eventIds' => []]);
        $events->expects(self::once())->method('findById')->with(self::CHAPTER_ID)->willReturn($chapter);

        $result = $this->preview($events, $this->allowedTargetAccess())
            ->fromAuthorizedIndexOccurrence($source, 1, self::CHAPTER_ID, self::COORDINATE, []);

        self::assertSame('Latest body', $result['content']);
    }

    public function testMissingChapterRemainsUnavailableWithoutFetching(): void
    {
        $source = $this->source([['a', self::COORDINATE]]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findWorkspacePreviewTargets')
            ->with([self::COORDINATE], [])
            ->willReturn(['coordinates' => [], 'eventIds' => []]);
        $events->expects(self::never())->method('findById');
        $sourceAccess = $this->accessWithoutTargetPolicy($events);

        $this->expectException(NotFoundHttpException::class);
        $this->preview($events, $sourceAccess)->fromAuthorizedIndexOccurrence(
            $source, 1, self::CHAPTER_ID, self::COORDINATE, [],
        );
    }

    public function testChangedTargetIdentityFailsBeforeReturningBody(): void
    {
        $source = $this->source([['a', self::COORDINATE]]);
        $metadata = $this->chapter('', [['d', 'chapter-one']]);
        $changed = $this->chapter('must not be returned', [['d', 'changed-coordinate']]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findWorkspacePreviewTargets')
            ->with([self::COORDINATE], [])
            ->willReturn(['coordinates' => [self::COORDINATE => $metadata], 'eventIds' => []]);
        $events->expects(self::once())->method('findById')->with(self::CHAPTER_ID)->willReturn($changed);
        $sourceAccess = $this->accessWithoutTargetPolicy($events);

        $this->expectException(NotFoundHttpException::class);
        $this->preview($events, $sourceAccess)->fromAuthorizedIndexOccurrence(
            $source, 1, self::CHAPTER_ID, self::COORDINATE, [],
        );
    }

    /** @dataProvider scopedChapterCases */
    public function testScopedSourceOrChapterFailsBeforeReturningBody(string $scopeLocation): void
    {
        $source = $this->source([['a', self::COORDINATE, '', self::CHAPTER_ID]]);
        $metadata = $this->chapter('', [['d', 'chapter-one']]);
        $chapter = $this->chapter('secret body', [['d', 'chapter-one']]);
        if ($scopeLocation === 'source') {
            $source->setTags([['d', 'index'], ['a', self::COORDINATE, '', self::CHAPTER_ID], ['s', 'members']]);
        } elseif ($scopeLocation === 'metadata') {
            $metadata->setTags([['d', 'chapter-one'], ['s']]);
        } else {
            $chapter->setTags([['d', 'chapter-one'], ['s', 'members']]);
        }
        $events = $this->createMock(EventRepository::class);
        $events->expects($scopeLocation === 'source' ? self::never() : self::once())
            ->method('findWorkspacePreviewTargets')
            ->willReturn(['coordinates' => [], 'eventIds' => [self::CHAPTER_ID => $metadata]]);
        $events->expects($scopeLocation === 'body' ? self::once() : self::never())
            ->method('findById')->willReturn($chapter);
        $this->expectException(NotFoundHttpException::class);
        $this->preview($events, $this->accessWithoutTargetPolicy($events))->fromAuthorizedIndexOccurrence(
            $source, 1, self::CHAPTER_ID, self::COORDINATE, [],
        );
    }

    public static function scopedChapterCases(): iterable
    {
        yield 'scoped source' => ['source'];
        yield 'scoped metadata' => ['metadata'];
        yield 'scope changed before body read' => ['body'];
    }

    public function testChangedUnpinnedLocalRevisionInvalidatesPreviewProof(): void
    {
        $source = $this->source([['a', self::COORDINATE]]);
        $metadata = $this->chapter('', [['d', 'chapter-one']]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findWorkspacePreviewTargets')
            ->with([self::COORDINATE], [])
            ->willReturn(['coordinates' => [self::COORDINATE => $metadata], 'eventIds' => []]);
        $events->expects(self::never())->method('findById');
        $sourceAccess = $this->accessWithoutTargetPolicy($events);

        $this->expectException(NotFoundHttpException::class);
        $this->preview($events, $sourceAccess)->fromAuthorizedIndexOccurrence(
            $source,
            1,
            str_repeat('e', 64),
            self::COORDINATE,
            [],
        );
    }

    /** @dataProvider blockedTargetPolicies */
    public function testMutedHiddenSuppressedAndDeletedTargetsFailClosed(string $policy): void
    {
        $source = $this->source([['a', self::COORDINATE]]);
        $metadata = $this->chapter('', [['d', 'chapter-one']]);
        $chapter = $this->chapter('must not be returned', [['d', 'chapter-one']]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findWorkspacePreviewTargets')
            ->willReturn(['coordinates' => [self::COORDINATE => $metadata], 'eventIds' => []]);
        $events->expects(self::never())->method('findById');

        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $muted = $policy === 'muted' ? [self::AUTHOR] : [];
        if ($policy === 'muted') {
            $hidden->expects(self::never())->method('findHiddenCoordinates');
            $authors->expects(self::never())->method('assertReadable');
            $deletions->expects(self::never())->method('isSuppressed');
        } elseif ($policy === 'hidden') {
            $hidden->expects(self::once())->method('findHiddenCoordinates')->with([self::COORDINATE])
                ->willReturn([self::COORDINATE]);
            $authors->expects(self::never())->method('assertReadable');
            $deletions->expects(self::never())->method('isSuppressed');
        } elseif ($policy === 'suppressed') {
            $hidden->expects(self::once())->method('findHiddenCoordinates')->willReturn([]);
            $authors->expects(self::once())->method('assertReadable')->with(self::AUTHOR)
                ->willThrowException(new NotFoundHttpException());
            $deletions->expects(self::never())->method('isSuppressed');
        } else {
            $hidden->expects(self::once())->method('findHiddenCoordinates')->willReturn([]);
            $authors->expects(self::once())->method('assertReadable')->with(self::AUTHOR);
            $deletions->expects(self::once())->method('isSuppressed')
                ->with(self::CHAPTER_ID, 30041, self::AUTHOR, 'chapter-one', 456)
                ->willReturn(true);
        }

        $access = new PublicationTocSourceAccess(
            $events,
            $this->presentations(),
            $deletions,
            $hidden,
            $authors,
        );
        try {
            $this->preview($events, $access)->fromAuthorizedIndexOccurrence(
                $source, 1, self::CHAPTER_ID, self::COORDINATE, $muted,
            );
            self::fail('A target blocked by current policy must not be previewed.');
        } catch (NotFoundHttpException) {
            self::assertTrue(true);
        }
    }

    public static function blockedTargetPolicies(): iterable
    {
        yield 'reader muted' => ['muted'];
        yield 'coordinate hidden' => ['hidden'];
        yield 'author suppressed' => ['suppressed'];
        yield 'tombstoned' => ['deleted'];
    }

    private function preview(EventRepository $events, PublicationTocSourceAccess $access): PublicationChapterPreview
    {
        return new PublicationChapterPreview($events, $this->presentations(), $access);
    }

    private function allowedTargetAccess(): PublicationTocSourceAccess
    {
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->expects(self::once())->method('findHiddenCoordinates')->with([self::COORDINATE])->willReturn([]);
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $authors->expects(self::once())->method('assertReadable')->with(self::AUTHOR);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::once())->method('isSuppressed')
            ->with(self::CHAPTER_ID, 30041, self::AUTHOR, 'chapter-one', 456)->willReturn(false);

        return new PublicationTocSourceAccess(
            $this->createMock(EventRepository::class),
            $this->presentations(),
            $deletions,
            $hidden,
            $authors,
        );
    }

    private function accessWithoutTargetPolicy(EventRepository $events): PublicationTocSourceAccess
    {
        return new PublicationTocSourceAccess(
            $events,
            $this->presentations(),
            $this->createStub(DeletedEventRepository::class),
            $this->createStub(HiddenCoordinateRepository::class),
            $this->createStub(ContentAuthorAccessPolicy::class),
        );
    }

    private function presentations(): EventPresentationFactory
    {
        return new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service());
    }

    /** @param list<array<int, mixed>> $references */
    private function source(array $references): Event
    {
        $event = new Event();
        $event->setId(self::SOURCE_ID);
        $event->setPubkey(self::AUTHOR);
        $event->setKind(30040);
        $event->setCreatedAt(123);
        $event->setDTag('index');
        $event->setTags([['d', 'index'], ...$references]);

        return $event;
    }

    /** @param list<array<int, mixed>> $tags */
    private function chapter(string $content, array $tags): Event
    {
        $event = new Event();
        $event->setId(self::CHAPTER_ID);
        $event->setPubkey(self::AUTHOR);
        $event->setKind(30041);
        $event->setCreatedAt(456);
        $event->setDTag('chapter-one');
        $event->setTags($tags);
        $event->setContent($content);

        return $event;
    }
}
