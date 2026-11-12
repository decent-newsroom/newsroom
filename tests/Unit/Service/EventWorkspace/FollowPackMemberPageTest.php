<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\EventWorkspace;

use App\Entity\Event;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventWorkspaceSourceAdapter;
use App\Service\EventWorkspace\FollowPackMemberPage;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use PHPUnit\Framework\TestCase;

final class FollowPackMemberPageTest extends TestCase
{
    private const SOURCE_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const MEMBER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private FollowPackMemberPage $pages;
    private NostrNip19Service $nip19;

    protected function setUp(): void
    {
        $identities = new RecordIdentityService();
        $this->nip19 = new NostrNip19Service();
        $this->pages = new FollowPackMemberPage(
            new EventWorkspaceSourceAdapter($identities),
            new EventPresentationFactory($identities, $this->nip19),
            $this->nip19,
        );
    }

    public function testMembersRetainOrderDuplicatesAndSourcePositionsWithoutMutation(): void
    {
        $mixedCase = str_repeat('aB', 32);
        $tags = [
            ['d', 'pack'],
            ['p', self::MEMBER, 'wss://one.example'],
            ['e', str_repeat('c', 64)],
            ['p', $mixedCase],
            ['a', '30023:' . self::MEMBER . ':article'],
            ['t', 'nostr'],
            ['p', self::MEMBER, 'wss://two.example'],
            ['P', self::MEMBER],
        ];
        $event = $this->event($tags);
        $event->setId(strtoupper(self::SOURCE_ID));
        $event->setContent('opaque private content, not a member source');
        $event->setSig('unchanged-signature');
        $before = serialize($event);

        self::assertSame([
            'eventId' => self::SOURCE_ID,
            'members' => [
                ['position' => 1, 'rawValue' => self::MEMBER, 'npub' => $this->nip19->encodeNpub(self::MEMBER)],
                ['position' => 3, 'rawValue' => $mixedCase, 'npub' => $this->nip19->encodeNpub(strtolower($mixedCase))],
                ['position' => 6, 'rawValue' => self::MEMBER, 'npub' => $this->nip19->encodeNpub(self::MEMBER)],
            ],
            'page' => 1,
            'hasNext' => false,
            'hasPrevious' => false,
        ], $this->pages->fromAuthorizedEvent($event));
        self::assertSame($before, serialize($event));
    }

    public function testMalformedMembersRemainVisibleUsingPresentationRawValues(): void
    {
        $result = $this->pages->fromAuthorizedEvent($this->event([
            ['p'],
            ['p', 123],
            ['p', 'not-hex'],
            ['p', str_repeat('g', 64)],
            ['p', ''],
            ['p', self::MEMBER],
        ]));

        self::assertSame([
            ['position' => 0, 'rawValue' => null, 'npub' => null],
            ['position' => 1, 'rawValue' => null, 'npub' => null],
            ['position' => 2, 'rawValue' => 'not-hex', 'npub' => null],
            ['position' => 3, 'rawValue' => str_repeat('g', 64), 'npub' => null],
            ['position' => 4, 'rawValue' => '', 'npub' => null],
            ['position' => 5, 'rawValue' => self::MEMBER, 'npub' => $this->nip19->encodeNpub(self::MEMBER)],
        ], $result['members']);
    }

    /**
     * @dataProvider pageCases
     */
    public function testPagesCountMemberOccurrencesNotTagPositions(
        int $memberCount,
        int $page,
        int $expectedCount,
        bool $hasNext,
    ): void {
        $tags = [['d', 'pack']];
        for ($i = 0; $i < $memberCount; ++$i) {
            $tags[] = ['t', 'unrelated'];
            // Invalid members still occupy their own slot on the page.
            $tags[] = $i % 2 === 0 ? ['p', self::MEMBER] : ['p'];
            $tags[] = ['e', str_repeat('c', 64)];
        }

        $result = $this->pages->fromAuthorizedEvent($this->event($tags), $page);

        self::assertSame(self::SOURCE_ID, $result['eventId']);
        self::assertSame($page, $result['page']);
        self::assertCount($expectedCount, $result['members']);
        self::assertSame($hasNext, $result['hasNext']);
        self::assertSame($page > 1, $result['hasPrevious']);
        $positions = [];
        for ($i = 0; $i < $expectedCount; ++$i) {
            $positions[] = 2 + (($page - 1) * FollowPackMemberPage::PAGE_SIZE + $i) * 3;
        }
        self::assertSame($positions, array_column($result['members'], 'position'));
        self::assertSame(array_values($result['members']), $result['members']);
    }

    public static function pageCases(): iterable
    {
        yield 'empty' => [0, 1, 0, false];
        yield 'empty later page' => [0, 2, 0, false];
        yield 'exact first page' => [50, 1, 50, false];
        yield 'one beyond first page' => [51, 1, 50, true];
        yield 'second page singleton' => [51, 2, 1, false];
        yield 'exact second page' => [100, 2, 50, false];
        yield 'third page available' => [101, 2, 50, true];
        yield 'third page singleton' => [101, 3, 1, false];
        yield 'out of range' => [51, 3, 0, false];
        yield 'largest safe offset' => [1, intdiv(PHP_INT_MAX, 50) + 1, 0, false];
        yield 'first overflowing offset' => [1, intdiv(PHP_INT_MAX, 50) + 2, 0, false];
        yield 'maximum page' => [1, PHP_INT_MAX, 0, false];
    }

    /**
     * @dataProvider invalidPages
     */
    public function testRejectsNonPositivePages(int $page): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->pages->fromAuthorizedEvent($this->event([]), $page);
    }

    public static function invalidPages(): iterable
    {
        yield [0];
        yield [-1];
        yield [PHP_INT_MIN];
    }

    /**
     * @dataProvider unsupportedKinds
     */
    public function testRejectsOtherKindsIncludingContactLists(int $kind): void
    {
        $event = $this->event([['p', self::MEMBER]]);
        $event->setKind($kind);
        $this->expectException(\InvalidArgumentException::class);

        $this->pages->fromAuthorizedEvent($event);
    }

    public static function unsupportedKinds(): iterable
    {
        yield 'contact list' => [3];
        yield 'publication' => [30040];
        yield 'unknown' => [12345];
    }

    /** @param array<array-key, mixed> $tags */
    private function event(array $tags): Event
    {
        $event = new Event();
        $event->setId(self::SOURCE_ID);
        $event->setKind(39089);
        $event->setPubkey(str_repeat('c', 64));
        $event->setCreatedAt(1710000000);
        $event->setTags($tags);

        return $event;
    }
}
