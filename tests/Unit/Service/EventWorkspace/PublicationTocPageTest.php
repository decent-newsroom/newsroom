<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\EventWorkspace;

use App\Dto\EventWorkspace\EventReferenceStatus;
use App\Entity\Event;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventWorkspaceSourceAdapter;
use App\Service\EventWorkspace\PublicationTocPage;
use App\Service\EventWorkspace\PublicationTocTraversalSigner;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\ContentAuthorAccessPolicy;
use PHPUnit\Framework\TestCase;

final class PublicationTocPageTest extends TestCase
{
    private function pages(
        ?EventRepository $events = null,
        ?DeletedEventRepository $deletions = null,
        ?ContentAuthorAccessPolicy $authorAccess = null,
        ?HiddenCoordinateRepository $hiddenCoordinates = null,
    ): PublicationTocPage
    {
        $identities = new RecordIdentityService();
        if ($events === null) {
            $events = $this->createMock(EventRepository::class);
            $events->method('findPublicationIndexTargets')->willReturn(['coordinates' => [], 'eventIds' => []]);
            $events->method('findWorkspacePreviewTargets')->willReturn(['coordinates' => [], 'eventIds' => []]);
        }

        return new PublicationTocPage(
            new EventWorkspaceSourceAdapter($identities),
            new EventPresentationFactory($identities, new NostrNip19Service()),
            $events,
            $deletions ?? $this->createMock(DeletedEventRepository::class),
            $authorAccess ?? $this->createMock(ContentAuthorAccessPolicy::class),
            $hiddenCoordinates ?? $this->createMock(HiddenCoordinateRepository::class),
            new PublicationTocTraversalSigner('test-framework-secret'),
        );
    }

    public function testOccurrencePollResolvesOnlyRequestedPositionWithoutRemovingSiblings(): void
    {
        $coordinate = '30040:' . str_repeat('b', 64) . ':missing';
        $pin = str_repeat('c', 64);
        $event = $this->event([
            ['d', 'book'], ['a', $coordinate], ['a', $coordinate, '', $pin],
            ['a', $coordinate], ['a', '30041:' . str_repeat('b', 64) . ':section'],
        ]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findPublicationIndexTargets')
            ->with([], [$pin])->willReturn(['coordinates' => [], 'eventIds' => []]);
        $page = $this->pages($events)->fromAuthorizedEvent($event, resolutionPosition: 2);
        self::assertSame([1, 2, 3, 4], array_map(static fn (array $entry): int => $entry['reference']->position, $page['entries']));
        self::assertNull($page['entries'][0]['targetResolution']);
        self::assertSame('unavailable', $page['entries'][1]['targetResolution']);
        self::assertNull($page['entries'][2]['targetResolution']);
    }

    public function testOrderedContentOccurrencesKeepNestedDuplicatesMalformedAddressesAndPins(): void
    {
        $coordinate = '30041:' . str_repeat('b', 64) . ':chapter:one';
        $pin = str_repeat('c', 64);
        $event = $this->event([
            ['d', 'book'],
            ['a', $coordinate, 'javascript:untrusted', strtoupper($pin)],
            ['e', str_repeat('d', 64)],
            ['a', '30040:' . str_repeat('b', 64) . ':nested'],
            ['a', $coordinate],
            ['a', 'bad-address', '', $pin],
            ['a'],
            ['a', 123],
            ['a', $coordinate, '', 'not-an-event-id'],
            ['a', $coordinate, '', ['not', 'a', 'string']],
            ['E', str_repeat('e', 64)],
            ['p', str_repeat('f', 64)],
        ]);
        $event->setContent('never expose body');
        $event->setSig('unchanged');
        $before = serialize($event);
        $result = $this->pages()->fromAuthorizedEvent($event);
        $references = array_column($result['entries'], 'reference');

        self::assertSame([1, 3, 4, 5, 6, 7, 8, 9], array_column($references, 'position'));
        self::assertSame($coordinate, $references[0]->target);
        self::assertSame('chapter:one', $references[0]->targetIdentifier);
        self::assertSame($pin, $references[0]->versionEventId);
        self::assertSame(strtoupper($pin), $result['entries'][0]['rawVersionPin']);
        self::assertSame(30040, $references[1]->targetKind);
        self::assertSame($coordinate, $references[2]->target);
        self::assertNull($references[2]->versionEventId);
        self::assertSame(EventReferenceStatus::Unresolved, $references[0]->status);
        self::assertSame(EventReferenceStatus::Invalid, $references[3]->status);
        self::assertSame($pin, $references[3]->versionEventId);
        self::assertNull($references[4]->rawValue);
        self::assertNull($references[5]->rawValue);
        self::assertSame(EventReferenceStatus::AmbiguousVersion, $references[6]->status);
        self::assertNull($references[6]->versionEventId);
        self::assertSame('not-an-event-id', $result['entries'][6]['rawVersionPin']);
        self::assertTrue($result['entries'][6]['invalidVersionPin']);
        self::assertTrue($result['entries'][7]['invalidVersionPin']);
        self::assertSame(EventReferenceStatus::Invalid, $references[7]->status);
        self::assertStringNotContainsString('never expose body', serialize($result));
        self::assertSame($before, serialize($event));
    }

    public function testNestedIndexesResolveOnlyVisibleAddressOccurrencesAndPreserveDuplicates(): void
    {
        $author = str_repeat('c', 64);
        $coordinate = '30040:' . $author . ':nested';
        $missing = '30040:' . str_repeat('d', 64) . ':missing';
        $target = $this->index(str_repeat('1', 64), $author, 'nested', [
            ['title', 'Authorized nested title'],
            ['summary', 'Authorized summary'],
            ['image', 'https://private.example/image.jpg'],
        ], 'child body must never leak');
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findPublicationIndexTargets')
            ->with([$coordinate, $coordinate, $missing], [])
            ->willReturn(['coordinates' => [$coordinate => $target], 'eventIds' => []]);
        $access = $this->createMock(ContentAuthorAccessPolicy::class);
        $access->expects(self::once())->method('suppressedPubkeys')->with([$author])->willReturn([]);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::once())->method('findSuppressedEventIds')->with([$target])->willReturn([]);

        $source = $this->event([
            ['d', 'parent'],
            ['a', $coordinate],
            ['e', str_repeat('1', 64)],
            ['a', $coordinate],
            ['a', $missing],
        ]);
        $source->setId(str_repeat('2', 64));
        $page = $this->pages($repository, $deletions, $access)->fromAuthorizedEvent($source);

        self::assertSame(['available', 'available', 'unavailable'], array_column($page['entries'], 'targetResolution'));
        self::assertSame('Authorized nested title', $page['entries'][0]['target']['title']);
        self::assertSame($target->getId(), $page['entries'][1]['target']['eventId']);
        self::assertNull($page['entries'][2]['target']);
        self::assertNotSame(
            $page['entries'][0]['nestedOccurrenceId'],
            $page['entries'][1]['nestedOccurrenceId'],
            'Repeated/shared references remain independent occurrences.',
        );
        self::assertSame($coordinate, $page['entries'][0]['reference']->target);
        self::assertStringNotContainsString('child body must never leak', serialize($page));
        self::assertStringNotContainsString('private.example', serialize($page));
    }

    public function testExactPinsResolveByEventIdValidateCoordinateAndNeverFallback(): void
    {
        $author = str_repeat('c', 64);
        $validPin = str_repeat('3', 64);
        $mismatchedPin = str_repeat('4', 64);
        $missingPin = str_repeat('5', 64);
        $coordinate = '30040:' . $author . ':nested';
        $valid = $this->index($validPin, $author, 'nested', [['title', 'Pinned title']]);
        $mismatch = $this->index($mismatchedPin, $author, 'different', [['title', 'Must stay hidden']]);
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findPublicationIndexTargets')
            ->with([], [$validPin, $mismatchedPin, $missingPin])
            ->willReturn(['coordinates' => [], 'eventIds' => [$validPin => $valid, $mismatchedPin => $mismatch]]);
        $access = $this->createMock(ContentAuthorAccessPolicy::class);
        $access->expects(self::once())->method('suppressedPubkeys')->with([$author])->willReturn([]);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::once())->method('findSuppressedEventIds')->with([$valid])->willReturn([]);

        $source = $this->event([
            ['d', 'parent'],
            ['a', $coordinate, '', $validPin],
            ['a', $coordinate, '', $mismatchedPin],
            ['a', $coordinate, '', $missingPin],
        ]);
        $page = $this->pages($repository, $deletions, $access)->fromAuthorizedEvent($source);

        self::assertSame('available', $page['entries'][0]['targetResolution']);
        self::assertSame($validPin, $page['entries'][0]['target']['eventId']);
        self::assertSame('unavailable', $page['entries'][1]['targetResolution']);
        self::assertNull($page['entries'][1]['target']);
        self::assertSame('unavailable', $page['entries'][2]['targetResolution']);
        self::assertStringNotContainsString('Must stay hidden', serialize($page));
    }

    public function testLocallyAvailableExactChapterPinGetsPolicyCheckedMetadataOnly(): void
    {
        $author = str_repeat('c', 64);
        $pin = str_repeat('3', 64);
        $coordinate = '30041:' . $author . ':chapter:one';
        $chapter = new Event();
        $chapter->setId($pin);
        $chapter->setKind(30041);
        $chapter->setPubkey($author);
        $chapter->setCreatedAt(456);
        $chapter->setDTag('chapter:one');
        $chapter->setTags([['d', 'chapter:one'], ['title', 'Pinned chapter'], ['summary', 'Pinned summary']]);
        $chapter->setContent('chapter body must not leak');

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findWorkspacePreviewTargets')
            ->with([], [$pin])
            ->willReturn(['coordinates' => [], 'eventIds' => [$pin => $chapter]]);
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->expects(self::once())->method('findHiddenCoordinates')->with([$coordinate])->willReturn([]);
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $authors->expects(self::once())->method('suppressedPubkeys')->with([$author])->willReturn([]);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::once())->method('findSuppressedEventIds')->with([$chapter])->willReturn([]);

        $source = $this->event([
            ['d', 'book'],
            ['a', $coordinate, '', $pin],
        ]);
        $page = $this->pages($repository, $deletions, $authors, $hidden)->fromAuthorizedEvent(
            $source,
            readerMutedPubkeys: [],
        );

        self::assertSame('available', $page['entries'][0]['targetResolution']);
        self::assertSame($pin, $page['entries'][0]['target']['eventId']);
        self::assertSame('Pinned chapter', $page['entries'][0]['target']['title']);
        self::assertSame('Pinned summary', $page['entries'][0]['target']['summary']);
        self::assertStringNotContainsString('chapter body must not leak', serialize($page));
    }

    public function testSuppressionAndTombstonesKeepOccurrencesButHideAllTargetFields(): void
    {
        $suppressedAuthor = str_repeat('c', 64);
        $deletedAuthor = str_repeat('d', 64);
        $suppressed = $this->index(str_repeat('5', 64), $suppressedAuthor, 'suppressed', [
            ['title', 'Suppressed existence marker'],
        ]);
        $deleted = $this->index(str_repeat('6', 64), $deletedAuthor, 'deleted', [
            ['title', 'Deleted existence marker'],
        ]);
        $suppressedCoordinate = '30040:' . $suppressedAuthor . ':suppressed';
        $deletedCoordinate = '30040:' . $deletedAuthor . ':deleted';
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findPublicationIndexTargets')
            ->willReturn([
                'coordinates' => [
                    $suppressedCoordinate => $suppressed,
                    $deletedCoordinate => $deleted,
                ],
                'eventIds' => [],
            ]);
        $access = $this->createMock(ContentAuthorAccessPolicy::class);
        $access->expects(self::once())
            ->method('suppressedPubkeys')
            ->with([$suppressedAuthor, $deletedAuthor])
            ->willReturn([$suppressedAuthor]);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::once())
            ->method('findSuppressedEventIds')
            ->with(self::callback(static fn (array $events): bool => count($events) === 2))
            ->willReturn([str_repeat('6', 64)]);

        $source = $this->event([
            ['d', 'parent'],
            ['a', $suppressedCoordinate],
            ['a', $deletedCoordinate],
        ]);
        $page = $this->pages($repository, $deletions, $access)->fromAuthorizedEvent($source);

        self::assertSame(['unavailable', 'unavailable'], array_column($page['entries'], 'targetResolution'));
        self::assertSame([$suppressedCoordinate, $deletedCoordinate], array_map(
            static fn (array $entry): ?string => $entry['reference']->rawValue,
            $page['entries'],
        ));
        self::assertSame([null, null], array_column($page['entries'], 'target'));
        self::assertStringNotContainsString('existence marker', serialize($page));
        self::assertStringNotContainsString(str_repeat('5', 64), serialize($page));
    }

    public function testHiddenCoordinatesAndReaderMutesHideTargetsBeforeMetadata(): void
    {
        $hiddenAuthor = str_repeat('a', 64);
        $mutedAuthor = str_repeat('b', 64);
        $visibleAuthor = str_repeat('c', 64);
        $hiddenCoordinate = '30040:' . $hiddenAuthor . ':hidden';
        $mutedCoordinate = '30040:' . $mutedAuthor . ':muted';
        $visibleCoordinate = '30040:' . $visibleAuthor . ':visible';
        $hidden = $this->index(str_repeat('1', 64), $hiddenAuthor, 'hidden', [['title', 'Hidden target title']]);
        $muted = $this->index(str_repeat('2', 64), $mutedAuthor, 'muted', [['title', 'Muted target title']]);
        $visible = $this->index(str_repeat('3', 64), $visibleAuthor, 'visible', [['title', 'Visible target title']]);
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findPublicationIndexTargets')
            ->with([$hiddenCoordinate, $mutedCoordinate, $visibleCoordinate], [])
            ->willReturn(['coordinates' => [
                $hiddenCoordinate => $hidden,
                $mutedCoordinate => $muted,
                $visibleCoordinate => $visible,
            ], 'eventIds' => []]);
        $hiddenCoordinates = $this->createMock(HiddenCoordinateRepository::class);
        $hiddenCoordinates->expects(self::once())
            ->method('findHiddenCoordinates')
            ->with([$hiddenCoordinate, $mutedCoordinate, $visibleCoordinate])
            ->willReturn([$hiddenCoordinate]);
        $access = $this->createMock(ContentAuthorAccessPolicy::class);
        $access->expects(self::once())->method('suppressedPubkeys')->with([$visibleAuthor])->willReturn([]);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::once())->method('findSuppressedEventIds')->with([$visible])->willReturn([]);

        $source = $this->event([
            ['d', 'parent'],
            ['a', $hiddenCoordinate],
            ['a', $mutedCoordinate],
            ['a', $visibleCoordinate],
        ]);
        $page = $this->pages($repository, $deletions, $access, $hiddenCoordinates)
            ->fromAuthorizedEvent($source, readerMutedPubkeys: [$mutedAuthor]);

        self::assertSame(['unavailable', 'unavailable', 'available'], array_column($page['entries'], 'targetResolution'));
        self::assertSame([null, null, 'Visible target title'], array_map(
            static fn (array $entry): ?string => $entry['target']['title'] ?? null,
            $page['entries'],
        ));
        self::assertStringNotContainsString('Hidden target title', serialize($page));
        self::assertStringNotContainsString('Muted target title', serialize($page));
        self::assertSame([$hiddenCoordinate, $mutedCoordinate, $visibleCoordinate], array_map(
            static fn (array $entry): ?string => $entry['reference']->rawValue,
            $page['entries'],
        ));
    }

    public function testNestedTraversalSignsOccurrenceAndPaginationContinuity(): void
    {
        $author = str_repeat('c', 64);
        $parentId = str_repeat('8', 64);
        $childId = str_repeat('9', 64);
        $coordinate = '30040:' . $author . ':nested';
        $child = $this->index($childId, $author, 'nested', [['title', 'Nested']]);
        $repository = $this->createMock(EventRepository::class);
        $repository->method('findPublicationIndexTargets')->willReturn([
            'coordinates' => [$coordinate => $child],
            'eventIds' => [],
        ]);
        $access = $this->createMock(ContentAuthorAccessPolicy::class);
        $access->method('suppressedPubkeys')->willReturn([]);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->method('findSuppressedEventIds')->willReturn([]);
        $hiddenCoordinates = $this->createMock(HiddenCoordinateRepository::class);
        $hiddenCoordinates->method('findHiddenCoordinates')->willReturn([]);

        $signer = new PublicationTocTraversalSigner('test-framework-secret');
        $ancestorPath = [str_repeat('7', 64)];
        $parent = $this->index($parentId, str_repeat('d', 64), 'parent', [
            ['a', $coordinate],
            ...array_fill(0, 50, ['a', '30041:' . str_repeat('e', 64) . ':chapter']),
        ]);
        $page = $this->pages($repository, $deletions, $access, $hiddenCoordinates)->fromAuthorizedEvent(
            $parent,
            1,
            $ancestorPath,
            str_repeat('f', 64),
            'reading_nook_nested_publication_toc',
        );

        self::assertTrue($page['hasNext']);
        self::assertTrue($signer->isValid(
            $parentId,
            2,
            $ancestorPath,
            str_repeat('f', 64),
            '30040:' . str_repeat('d', 64) . ':parent',
            $page['pageSignatures']['next'],
        ));
        $previousPage = $this->pages($repository, $deletions, $access, $hiddenCoordinates)->fromAuthorizedEvent(
            $parent,
            2,
            $ancestorPath,
            str_repeat('f', 64),
            'reading_nook_nested_publication_toc',
        );
        self::assertTrue($signer->isValid(
            $parentId,
            1,
            $ancestorPath,
            str_repeat('f', 64),
            '30040:' . str_repeat('d', 64) . ':parent',
            $previousPage['pageSignatures']['previous'],
        ));
        self::assertTrue($signer->isValid(
            $childId,
            1,
            [...$ancestorPath, $parentId],
            $page['entries'][0]['nestedOccurrenceId'],
            $coordinate,
            $page['entries'][0]['nestedSignature'],
        ));
        self::assertFalse($signer->isValid(
            $childId,
            1,
            $ancestorPath,
            $page['entries'][0]['nestedOccurrenceId'],
            $coordinate,
            $page['entries'][0]['nestedSignature'],
        ), 'A path reset invalidates the signed traversal.');
    }

    public function testCyclesDepthAndRepeatedOccurrencesHaveBoundedUniqueFrames(): void
    {
        $author = str_repeat('c', 64);
        $selfId = str_repeat('7', 64);
        $selfCoordinate = '30040:' . $author . ':self';
        $self = $this->index($selfId, $author, 'self', [
            ['title', 'Self'],
            ['a', $selfCoordinate],
            ['a', $selfCoordinate],
        ]);
        $repository = $this->createMock(EventRepository::class);
        $repository->method('findPublicationIndexTargets')->willReturn([
            'coordinates' => [$selfCoordinate => $self],
            'eventIds' => [],
        ]);
        $access = $this->createMock(ContentAuthorAccessPolicy::class);
        $access->method('suppressedPubkeys')->willReturn([]);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->method('findSuppressedEventIds')->willReturn([]);

        $cyclePage = $this->pages($repository, $deletions, $access)->fromAuthorizedEvent($self);
        self::assertSame([true, true], array_column($cyclePage['entries'], 'cycle'));
        self::assertSame([2, 3], array_map(
            static fn (array $entry): int => $entry['reference']->position,
            $cyclePage['entries'],
        ));
        self::assertSame([], array_filter(array_column($cyclePage['entries'], 'nestedOccurrenceId')));

        $deepPage = $this->pages($repository, $deletions, $access)->fromAuthorizedEvent(
            $this->event([['d', 'deep'], ['a', $selfCoordinate]]),
            1,
            array_map(static fn (int $i): string => sprintf('%064x', $i + 10), range(0, 4)),
            str_repeat('8', 64),
            'reading_nook_nested_publication_toc',
        );
        self::assertTrue($deepPage['entries'][0]['depthLimited']);
        self::assertNull($deepPage['entries'][0]['nestedOccurrenceId']);
        self::assertStringStartsWith('nook-toc-nested-', $deepPage['frameId']);
    }

    /** @dataProvider pageCases */
    public function testPaginationCountsOccurrencesNotAllTags(int $count, int $page, int $expected, bool $next): void
    {
        $tags = [['d', 'book']];
        for ($i = 0; $i < $count; ++$i) {
            $tags[] = ['e', str_repeat('b', 64)];
            $tags[] = $i % 2 === 0 ? ['a', '30041:' . str_repeat('b', 64) . ':chapter'] : ['a'];
        }
        $result = $this->pages()->fromAuthorizedEvent($this->event($tags), $page);
        self::assertCount($expected, $result['entries']);
        self::assertSame($next, $result['hasNext']);
        self::assertSame($page > 1, $result['hasPrevious']);
        if ($expected > 0) {
            self::assertSame(($page - 1) * 50 + 1, $result['start']);
            self::assertSame(2 + ($page - 1) * 100, $result['entries'][0]['reference']->position);
        }
    }

    public static function pageCases(): iterable
    {
        yield [0, 1, 0, false];
        yield [50, 1, 50, false];
        yield [51, 1, 50, true];
        yield [51, 2, 1, false];
        yield [100, 2, 50, false];
        yield [101, 2, 50, true];
        yield [101, 3, 1, false];
        yield [51, 3, 0, false];
        yield [1, intdiv(PHP_INT_MAX - 1, 50) + 1, 0, false];
        yield [1, intdiv(PHP_INT_MAX - 1, 50) + 2, 0, false];
        yield [1, PHP_INT_MAX, 0, false];
    }

    /** @dataProvider invalidInputs */
    public function testRejectsWrongKindAndNonPositivePages(int $kind, int $page): void
    {
        $event = $this->event([]);
        $event->setKind($kind);
        $this->expectException(\InvalidArgumentException::class);
        $this->pages()->fromAuthorizedEvent($event, $page);
    }

    public static function invalidInputs(): iterable
    {
        yield [3, 1];
        yield [39089, 1];
        yield [30045, 1];
        yield [30040, 0];
        yield [30040, -1];
        yield [30040, PHP_INT_MIN];
    }

    private function event(array $tags): Event
    {
        $event = new Event();
        $event->setId(str_repeat('a', 64));
        $event->setKind(30040);
        $event->setPubkey(str_repeat('b', 64));
        $event->setCreatedAt(123);
        $event->setTags($tags);

        return $event;
    }

    private function index(string $id, string $pubkey, string $dTag, array $tags, string $content = ''): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setKind(30040);
        $event->setPubkey($pubkey);
        $event->setCreatedAt(456);
        $event->setDTag($dTag);
        $event->setTags([['d', $dTag], ...$tags]);
        $event->setContent($content);

        return $event;
    }
}
