<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\EventWorkspace;

use App\Dto\EventWorkspace\EventReferenceStatus;
use App\Entity\Event;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventWorkspaceSourceAdapter;
use App\Service\EventWorkspace\PublicationTocPage;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use PHPUnit\Framework\TestCase;

final class PublicationTocPageTest extends TestCase
{
    private function pages(): PublicationTocPage
    {
        $identities = new RecordIdentityService();

        return new PublicationTocPage(
            new EventWorkspaceSourceAdapter($identities),
            new EventPresentationFactory($identities, new NostrNip19Service()),
        );
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
}
