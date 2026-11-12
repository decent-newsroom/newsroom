<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\EventWorkspace;

use App\Entity\Event;
use App\Service\EventWorkspace\BookmarkReferencePage;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventWorkspaceSourceAdapter;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use PHPUnit\Framework\TestCase;

final class BookmarkReferencePageTest extends TestCase
{
    private const SOURCE_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const EVENT_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const PUBKEY = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private BookmarkReferencePage $pages;
    private NostrNip19Service $nip19;

    protected function setUp(): void
    {
        $identities = new RecordIdentityService();
        $this->nip19 = new NostrNip19Service();
        $this->pages = new BookmarkReferencePage(
            new EventWorkspaceSourceAdapter($identities),
            new EventPresentationFactory($identities, $this->nip19),
            $this->nip19,
        );
    }

    public function testBookmarkTagsKeepOrderDuplicatesPositionsAndSourceIsUnchanged(): void
    {
        $address = '30023:' . self::PUBKEY . ':essay';
        $tags = [
            ['title', 'Saved'],
            ['e', self::EVENT_ID],
            ['p', self::PUBKEY],
            ['a', $address],
            ['t', 'nostr'],
            ['e', self::EVENT_ID],
            ['a'],
            ['t', ''],
            ['P', self::PUBKEY],
        ];
        $event = $this->event(10003, $tags);
        $event->setContent('opaque bookmark payload must not appear');
        $event->setSig('unchanged-signature');
        $before = serialize($event);

        self::assertSame([
            'eventId' => self::SOURCE_ID,
            'page' => 1,
            'entries' => [
                [
                    'position' => 1, 'type' => 'event', 'value' => $this->nip19->encodeNote(self::EVENT_ID),
                    'rawValue' => self::EVENT_ID, 'invalid' => false,
                ],
                [
                    'position' => 2, 'type' => 'profile', 'value' => $this->nip19->encodeNpub(self::PUBKEY),
                    'rawValue' => self::PUBKEY, 'invalid' => false,
                ],
                [
                    'position' => 3, 'type' => 'address',
                    'value' => $this->nip19->encodeAddr(self::PUBKEY, 'essay', 30023),
                    'rawValue' => $address, 'invalid' => false,
                ],
                [
                    'position' => 4, 'type' => 'topic', 'value' => '#nostr',
                    'rawValue' => 'nostr', 'invalid' => false,
                ],
                [
                    'position' => 5, 'type' => 'event', 'value' => $this->nip19->encodeNote(self::EVENT_ID),
                    'rawValue' => self::EVENT_ID, 'invalid' => false,
                ],
                ['position' => 6, 'type' => 'invalid', 'value' => null, 'rawValue' => null, 'invalid' => true],
                ['position' => 7, 'type' => 'invalid', 'value' => '', 'rawValue' => '', 'invalid' => true],
            ],
            'hasNext' => false,
            'hasPrevious' => false,
        ], $this->pages->fromAuthorizedEvent($event));
        self::assertSame($before, serialize($event));
    }

    public function testBookmarkSetReferencesAreSupportedAndPaginatedByOccurrence(): void
    {
        $tags = [['d', 'favorites']];
        for ($i = 0; $i < 51; ++$i) {
            $tags[] = ['t', 'topic-' . $i];
        }
        $event = $this->event(30003, $tags);

        $firstPage = $this->pages->fromAuthorizedEvent($event);
        self::assertCount(50, $firstPage['entries']);
        self::assertSame(1, $firstPage['entries'][0]['position']);
        self::assertSame('#topic-0', $firstPage['entries'][0]['value']);
        self::assertTrue($firstPage['hasNext']);

        $secondPage = $this->pages->fromAuthorizedEvent($event, 2);
        self::assertCount(1, $secondPage['entries']);
        self::assertSame(51, $secondPage['entries'][0]['position']);
        self::assertSame('#topic-50', $secondPage['entries'][0]['value']);
        self::assertFalse($secondPage['hasNext']);
        self::assertTrue($secondPage['hasPrevious']);
    }

    /** @dataProvider unsupportedKinds */
    public function testOtherKindsAreNotBookmarkSources(int $kind): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->pages->fromAuthorizedEvent($this->event($kind, []));
    }

    /** @return iterable<string, array{int}> */
    public static function unsupportedKinds(): iterable
    {
        yield 'contact list' => [3];
        yield 'follow pack' => [39089];
        yield 'publication index' => [30040];
    }

    /** @param array<array-key, mixed> $tags */
    private function event(int $kind, array $tags): Event
    {
        $event = new Event();
        $event->setId(self::SOURCE_ID);
        $event->setKind($kind);
        $event->setPubkey(self::PUBKEY);
        $event->setCreatedAt(1710000000);
        $event->setTags($tags);

        return $event;
    }
}
