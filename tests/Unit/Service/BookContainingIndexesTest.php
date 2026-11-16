<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Bookshelf\BookContainingIndexes;
use App\Entity\Event;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\ContentAuthorAccessPolicy;
use PHPUnit\Framework\TestCase;

final class BookContainingIndexesTest extends TestCase
{
    /**
     * @dataProvider parentCases
     * @param list<list<string>> $references
     */
    public function testCurrentParentMembership(
        array $references,
        bool $suppressed,
        bool $deleted,
        bool $hidden,
        bool $muted,
        bool $sensitive,
        bool $visible,
    ): void {
        $pubkey = str_repeat('a', 64);
        $book = $this->event(str_repeat('b', 64), $pubkey, [['d', 'book']]);
        $parent = $this->event(str_repeat('c', 64), $pubkey, [
            ['d', 'index:with:colons'], ['title', '<Parent>'], ...$references,
            ...($sensitive ? [['content-warning', 'warning']] : []),
        ]);
        $coordinate = '30040:' . $pubkey . ':index:with:colons';
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findReferencingEvents')
            ->with('a', '30040:' . $pubkey . ':book', [30040], 50, true)
            ->willReturn([$parent, $parent, $book]);
        $events->expects(self::once())->method('findPublicationIndexTargets')
            ->with([$coordinate], [])->willReturn(['coordinates' => [$coordinate => $parent], 'eventIds' => []]);
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $authors->method('suppressedPubkeys')->willReturn($suppressed ? [$pubkey] : []);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->method('findSuppressedEventIds')->willReturn($deleted ? [$parent->getId()] : []);
        $hiddenCoordinates = $this->createMock(HiddenCoordinateRepository::class);
        $hiddenCoordinates->method('isHidden')->with($coordinate)->willReturn($hidden);
        $nip19 = new NostrNip19Service();
        $service = new BookContainingIndexes(
            $events, new EventPresentationFactory(new RecordIdentityService(), $nip19),
            $authors, $deletions, $hiddenCoordinates,
        );

        self::assertSame($visible ? [[
            'title' => '<Parent>',
            'eventId' => $parent->getId(),
        ]] : [], $service->forBook($book, $muted ? [$pubkey] : []));
    }

    /** @return iterable<string, array{list<list<string>>, bool, bool, bool, bool, bool, bool}> */
    public static function parentCases(): iterable
    {
        $ref = ['a', '30040:' . str_repeat('a', 64) . ':book'];
        yield 'coordinate' => [[$ref], false, false, false, false, false, true];
        yield 'duplicate' => [[$ref, $ref], false, false, false, false, false, true];
        yield 'matching pin' => [[[$ref[0], $ref[1], '', str_repeat('b', 64)]], false, false, false, false, false, true];
        yield 'wrong pin' => [[[$ref[0], $ref[1], '', str_repeat('d', 64)]], false, false, false, false, false, false];
        yield 'malformed pin' => [[[$ref[0], $ref[1], '', 'bad-pin']], false, false, false, false, false, false];
        yield 'removed in current revision' => [[], false, false, false, false, false, false];
        yield 'suppressed author' => [[$ref], true, false, false, false, false, false];
        yield 'deleted' => [[$ref], false, true, false, false, false, false];
        yield 'hidden' => [[$ref], false, false, true, false, false, false];
        yield 'muted' => [[$ref], false, false, false, true, false, false];
        yield 'sensitive' => [[$ref], false, false, false, false, true, false];
        yield 'scoped audience' => [[$ref, ['s', 'members']], false, false, false, false, false, false];
        yield 'malformed scope marker' => [[$ref, ['s']], false, false, false, false, false, false];
    }

    public function testNoLocalParentsDoesNotResolveTargets(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->method('findReferencingEvents')->willReturn([]);
        $events->expects(self::never())->method('findPublicationIndexTargets');
        $nip19 = new NostrNip19Service();
        $service = new BookContainingIndexes(
            $events, new EventPresentationFactory(new RecordIdentityService(), $nip19),
            $this->createMock(ContentAuthorAccessPolicy::class),
            $this->createMock(DeletedEventRepository::class),
            $this->createMock(HiddenCoordinateRepository::class),
        );
        self::assertSame([], $service->forBook($this->event(
            str_repeat('b', 64), str_repeat('a', 64), [['d', 'book']],
        )));
    }

    public function testScopedBookDoesNotEnumerateContainingIndexes(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findReferencingEvents');
        $service = new BookContainingIndexes(
            $events, new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()),
            $this->createMock(ContentAuthorAccessPolicy::class),
            $this->createMock(DeletedEventRepository::class),
            $this->createMock(HiddenCoordinateRepository::class),
        );
        self::assertSame([], $service->forBook($this->event(
            str_repeat('b', 64), str_repeat('a', 64), [['d', 'book'], ['s', 'members']],
        )));
    }

    /** @param list<list<string>> $tags */
    private function event(string $id, string $pubkey, array $tags): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setPubkey($pubkey);
        $event->setKind(30040);
        $event->setTags($tags);
        $event->setContent('');

        return $event;
    }
}
