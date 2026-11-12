<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\EventWorkspace;

use App\Entity\Event;
use App\Service\EventWorkspace\BookshelfReferencePage;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventWorkspaceSourceAdapter;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use PHPUnit\Framework\TestCase;

final class BookshelfReferencePageTest extends TestCase
{
    use TargetPreviewResolverTestTrait;
    public function testOrderedRawDirectoryOccurrencesRetainPinsAndNeverExposeBodies(): void
    {
        $id = str_repeat('b', 64);
        $coordinate = '30040:' . str_repeat('c', 64) . ':book';
        $event = $this->event([
            ['d', 'my-book-collection'],
            ['a', $coordinate, 'javascript:untrusted', $id],
            ['e', $id, '', str_repeat('d', 64)],
            ['a', $coordinate],
            ['a'],
            ['e', 'bad-id'],
            ['a', $coordinate, '', 'bad-pin'],
            ['a', $coordinate, '', 123],
            ['p', str_repeat('e', 64)],
        ]);
        $before = serialize($event);
        $page = $this->pages()->fromAuthorizedEvent($event);
        self::assertSame([1, 2, 3, 4, 5, 6, 7], array_map(
            static fn (array $entry): int => $entry['reference']->position,
            $page['entries'],
        ));
        self::assertSame($id, $page['entries'][0]['reference']->versionEventId);
        self::assertNull($page['entries'][1]['reference']->versionEventId);
        self::assertFalse($page['entries'][1]['invalidVersionPin']);
        self::assertSame($coordinate, $page['entries'][2]['reference']->rawValue);
        self::assertSame('invalid', $page['entries'][3]['reference']->status->value);
        self::assertSame('bad-id', $page['entries'][4]['reference']->rawValue);
        self::assertTrue($page['entries'][5]['invalidVersionPin']);
        self::assertSame('bad-pin', $page['entries'][5]['rawVersionPin']);
        self::assertTrue($page['entries'][6]['invalidVersionPin']);
        self::assertSame($before, serialize($event));
        self::assertStringNotContainsString('secret body', serialize($page));
    }

    public function testPaginationIsBoundedByOccurrencesAndHandlesMaximumInteger(): void
    {
        $event = $this->event(array_fill(0, 51, ['e', str_repeat('b', 64)]));
        $first = $this->pages()->fromAuthorizedEvent($event);
        self::assertCount(50, $first['entries']);
        self::assertTrue($first['hasNext']);
        $second = $this->pages()->fromAuthorizedEvent($event, 2);
        self::assertCount(1, $second['entries']);
        self::assertSame(50, $second['entries'][0]['reference']->position);
        self::assertSame(51, $second['start']);
        self::assertFalse($second['hasNext']);
        self::assertTrue($second['hasPrevious']);
        self::assertSame([], $this->pages()->fromAuthorizedEvent($event, PHP_INT_MAX)['entries']);
    }

    public function testRejectsWrongKind(): void
    {
        $event = $this->event([]);
        $event->setKind(30040);
        $this->expectException(\InvalidArgumentException::class);
        $this->pages()->fromAuthorizedEvent($event);
    }

    public function testRejectsNonPositivePage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pages()->fromAuthorizedEvent($this->event([]), 0);
    }

    private function pages(): BookshelfReferencePage
    {
        $identities = new RecordIdentityService();

        return new BookshelfReferencePage(
            new EventWorkspaceSourceAdapter($identities),
            new EventPresentationFactory($identities, new NostrNip19Service()),
            $this->targetResolver(),
        );
    }

    /** @param list<array<array-key, mixed>> $tags */
    private function event(array $tags): Event
    {
        $event = new Event();
        $event->setId(str_repeat('a', 64));
        $event->setPubkey(str_repeat('c', 64));
        $event->setKind(30045);
        $event->setTags($tags);
        $event->setContent('secret body');

        return $event;
    }
}
