<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util\CommonMark;

use App\Enum\KindsEnum;
use App\Factory\ArticleFactory;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Util\CommonMark\Converter;
use nostriphant\NIP19\Bech32;
use PHPUnit\Framework\TestCase;
use Twig\Environment as TwigEnvironment;

final class NostrChapterEmbedTest extends TestCase
{
    public function testLocalKindOneNeventDefersWithOriginalExactReference(): void
    {
        $event = $this->exactEvent(1);
        // Hints do not change the exact target or determine the local kind.
        $reference = (string) Bech32::nevent(
            id: $event->id,
            relays: ['wss://relay.example.test'],
            author: str_repeat('c', 64),
            kind: 20,
        );
        $twig = $this->createMock(TwigEnvironment::class);
        $twig->expects(self::never())->method('render');

        $html = $this->renderNostrLink($twig, $reference, [], eventsById: [$event->id => $event]);

        self::assertSame(
            '<div class="nostr-deferred-embed" data-nostr-bech="' . $reference . '" data-nostr-type="nevent"></div>',
            $html,
        );
        self::assertStringNotContainsString($event->content, $html);
        self::assertStringNotContainsString('Private title', $html);
        self::assertStringNotContainsString('private warning', $html);
        self::assertStringNotContainsString('href=', $html);
    }

    public function testMissingNeventStillDefers(): void
    {
        $reference = (string) Bech32::nevent(id: str_repeat('b', 64), kind: 1);
        $twig = $this->createMock(TwigEnvironment::class);
        $twig->expects(self::never())->method('render');

        self::assertSame(
            '<div class="nostr-deferred-embed" data-nostr-bech="' . $reference . '" data-nostr-type="nevent"></div>',
            $this->renderNostrLink($twig, $reference, []),
        );
    }

    public function testLocalHighlightNeventIsDeferredForViewerSpecificSourceChecks(): void
    {
        $event = $this->exactEvent(KindsEnum::HIGHLIGHTS->value);
        $reference = (string) Bech32::nevent(id: $event->id, kind: KindsEnum::HIGHLIGHTS->value);
        $twig = $this->createMock(TwigEnvironment::class);
        $twig->expects(self::never())->method('render');

        self::assertSame(
            '<div class="nostr-deferred-embed" data-nostr-bech="' . $reference . '" data-nostr-type="highlight-nevent"></div>',
            $this->renderNostrLink($twig, $reference, [], eventsById: [$event->id => $event]),
        );
    }

    public function testLocalHighlightAddressIsDeferredForViewerSpecificSourceChecks(): void
    {
        $event = $this->exactEvent(KindsEnum::HIGHLIGHTS->value);
        $reference = (string) Bech32::naddr(kind: KindsEnum::HIGHLIGHTS->value, pubkey: $event->pubkey, identifier: 'highlight');
        $twig = $this->createMock(TwigEnvironment::class);
        $twig->expects(self::never())->method('render');

        self::assertSame(
            '<div class="nostr-deferred-embed" data-nostr-bech="' . $reference . '" data-nostr-type="highlight-naddr"></div>',
            $this->renderNostrLink($twig, $reference, ['9802:' . $event->pubkey . ':highlight' => $event]),
        );
    }

    public function testLocalKindOneInlineNeventRemainsAnEscapedLink(): void
    {
        $event = $this->exactEvent(1);
        $reference = (string) Bech32::nevent(id: $event->id, kind: 1);
        $twig = $this->createMock(TwigEnvironment::class);
        $twig->expects(self::never())->method('render');

        self::assertSame(
            '<a href="/e/' . $reference . '" class="nostr-link">read &lt;note&gt;</a>',
            $this->renderNostrLink($twig, $reference, [], 'read <note>', true, eventsById: [$event->id => $event]),
        );
    }

    /** @dataProvider legacyNeventKinds */
    public function testOtherLocalNeventKindsKeepLegacyCard(int $kind): void
    {
        $event = $this->exactEvent($kind);
        $reference = (string) Bech32::nevent(id: $event->id, kind: 1);
        $author = (object) ['name' => 'Cached author'];
        $twig = $this->createMock(TwigEnvironment::class);
        $twig->expects(self::once())->method('render')
            ->with('components/event_card.html.twig', [
                'event' => $event,
                'author' => $author,
                'nevent' => $reference,
            ])
            ->willReturn('<article>legacy event card</article>');

        self::assertSame('<article>legacy event card</article>', $this->renderNostrLink(
            $twig, $reference, [],
            eventsById: [$event->id => $event],
            metadataByHex: [$event->pubkey => $author],
        ));
    }

    public static function legacyNeventKinds(): iterable
    {
        yield 'picture' => [20];
        yield 'comment' => [1111];
        yield 'longform' => [30023];
        yield 'unknown' => [65000];
    }

    public function testLocalKindTwentyNoteKeepsPictureSpecialist(): void
    {
        $event = $this->exactEvent(20);
        $reference = (string) Bech32::note($event->id);
        $twig = $this->createMock(TwigEnvironment::class);
        $twig->expects(self::once())->method('render')
            ->with('/event/_kind20_picture.html.twig', ['event' => $event, 'embed' => true])
            ->willReturn('<article>picture</article>');

        self::assertSame('<article>picture</article>', $this->renderNostrLink(
            $twig, $reference, [], eventsById: [$event->id => $event],
        ));
    }

    public function testLocalKindOneNoteRemainsALink(): void
    {
        $event = $this->exactEvent(1);
        $reference = (string) Bech32::note($event->id);
        $twig = $this->createMock(TwigEnvironment::class);
        $twig->expects(self::never())->method('render');

        self::assertSame(
            '<a href="/e/' . $reference . '" class="nostr-link">' . $reference . '</a>',
            $this->renderNostrLink($twig, $reference, [], eventsById: [$event->id => $event]),
        );
    }

    public function testOtherLocalNaddrKeepsLegacyCard(): void
    {
        $event = $this->exactEvent(30045);
        $reference = (string) Bech32::naddr(kind: 30045, pubkey: $event->pubkey, identifier: 'index');
        $twig = $this->createMock(TwigEnvironment::class);
        $twig->expects(self::once())->method('render')
            ->with('components/event_card.html.twig', ['event' => $event, 'author' => null, 'naddr' => $reference])
            ->willReturn('<article>legacy index</article>');

        self::assertSame('<article>legacy index</article>', $this->renderNostrLink(
            $twig, $reference, ['30045:' . $event->pubkey . ':index' => $event],
        ));
    }

    public function testLocalPublicationIndexNaddrDefersWithOriginalCoordinate(): void
    {
        $pubkey = str_repeat('d', 64);
        $identifier = 'magazine';
        $relay = 'wss://relay.example.test';
        $naddr = (string) Bech32::naddr(
            kind: KindsEnum::PUBLICATION_INDEX->value,
            pubkey: $pubkey,
            identifier: $identifier,
            relays: [$relay],
        );
        $event = (object) [
            'id' => str_repeat('e', 64),
            'kind' => KindsEnum::PUBLICATION_INDEX->value,
            'pubkey' => $pubkey,
            'content' => '',
            'created_at' => 456,
            'tags' => [['d', $identifier], ['title', 'Magazine title']],
            'sig' => str_repeat('f', 128),
        ];
        $twig = $this->createMock(TwigEnvironment::class);
        $twig->expects(self::never())->method('render');

        self::assertSame(
            '<div class="nostr-deferred-embed" data-nostr-bech="' . $naddr . '" data-nostr-type="naddr"></div>',
            $this->renderNostrLink(
                $twig,
                $naddr,
                [KindsEnum::PUBLICATION_INDEX->value . ':' . $pubkey . ':' . $identifier => $event],
            ),
        );
    }

    public function testLocalPublicationContentNaddrDefersWithOriginalCoordinate(): void
    {
        $pubkey = str_repeat('a', 64);
        $identifier = 'intro';
        $relay = 'wss://chapter-relay.example.test';
        $naddr = (string) Bech32::naddr(
            kind: KindsEnum::PUBLICATION_CONTENT->value,
            pubkey: $pubkey,
            identifier: $identifier,
            relays: [$relay],
        );
        $event = (object) [
            'id' => str_repeat('b', 64),
            'kind' => KindsEnum::PUBLICATION_CONTENT->value,
            'pubkey' => $pubkey,
            'content' => "= Intro\n\nChapter body",
            'created_at' => 123,
            'tags' => [['d', $identifier], ['title', 'Intro chapter'], ['summary', 'Chapter summary']],
            'sig' => str_repeat('c', 128),
        ];

        $twig = $this->createMock(TwigEnvironment::class);
        $twig->expects(self::never())->method('render');

        self::assertSame(
            '<div class="nostr-deferred-embed" data-nostr-bech="' . $naddr . '" data-nostr-type="naddr"></div>',
            $this->renderNostrLink(
                $twig,
                $naddr,
                [KindsEnum::PUBLICATION_CONTENT->value . ':' . $pubkey . ':' . $identifier => $event],
            ),
        );
    }

    public function testLocalWikiNaddrDefersToSharedPreview(): void
    {
        $pubkey = str_repeat('a', 64);
        $identifier = 'wiki';
        $naddr = (string) Bech32::naddr(
            kind: KindsEnum::WIKI->value,
            pubkey: $pubkey,
            identifier: $identifier,
        );
        $event = (object) [
            'id' => str_repeat('b', 64),
            'kind' => KindsEnum::WIKI->value,
            'pubkey' => $pubkey,
            'content' => 'Wiki body',
            'created_at' => 123,
            'tags' => [['d', $identifier], ['title', 'Wiki title'], ['summary', 'Wiki summary']],
            'sig' => str_repeat('c', 128),
        ];
        $twig = $this->createMock(TwigEnvironment::class);
        $twig->expects(self::never())->method('render');

        self::assertSame(
            '<div class="nostr-deferred-embed" data-nostr-bech="' . $naddr . '" data-nostr-type="naddr"></div>',
            $this->renderNostrLink(
                $twig,
                $naddr,
                [KindsEnum::WIKI->value . ':' . $pubkey . ':' . $identifier => $event],
            ),
        );
    }

    public function testPublicationContentInlineNaddrLinksToChapterRoute(): void
    {
        $pubkey = str_repeat('a', 64);
        $identifier = 'intro';
        $naddr = (string) Bech32::naddr(
            kind: KindsEnum::PUBLICATION_CONTENT->value,
            pubkey: $pubkey,
            identifier: $identifier,
        );

        $html = $this->renderNostrLink(
            $this->createMock(TwigEnvironment::class),
            $naddr,
            [],
            'read chapter',
            true,
        );

        self::assertSame('<a href="/chapter/' . $naddr . '" class="nostr-link">read chapter</a>', $html);
    }

    public function testLocalLongformNaddrDefersWithOriginalCoordinateAndRelayHint(): void
    {
        $pubkey = str_repeat('d', 64);
        $identifier = 'article';
        $naddr = (string) Bech32::naddr(
            kind: KindsEnum::LONGFORM->value,
            pubkey: $pubkey,
            identifier: $identifier,
            relays: ['wss://relay.example.test'],
        );
        $event = (object) [
            'id' => str_repeat('e', 64),
            'kind' => KindsEnum::LONGFORM->value,
            'pubkey' => $pubkey,
            'content' => 'Article body',
            'created_at' => 456,
            'tags' => [['d', $identifier], ['title', 'Article title']],
            'sig' => str_repeat('f', 128),
        ];

        $twig = $this->createMock(TwigEnvironment::class);
        $articleFactory = $this->createMock(ArticleFactory::class);
        $articleFactory->expects(self::never())->method('createFromLongFormContentEvent');
        $twig->expects(self::never())->method('render');

        self::assertSame(
            '<div class="nostr-deferred-embed" data-nostr-bech="' . $naddr . '" data-nostr-type="naddr"></div>',
            $this->renderNostrLink(
                $twig,
                $naddr,
                [KindsEnum::LONGFORM->value . ':' . $pubkey . ':' . $identifier => $event],
                null,
                false,
                $articleFactory,
            ),
        );
    }

    public function testLocalLongformInlineNaddrRemainsAnArticleLink(): void
    {
        $pubkey = str_repeat('d', 64);
        $identifier = 'article';
        $naddr = (string) Bech32::naddr(
            kind: KindsEnum::LONGFORM->value,
            pubkey: $pubkey,
            identifier: $identifier,
        );
        $event = $this->exactEvent(KindsEnum::LONGFORM->value);
        $twig = $this->createMock(TwigEnvironment::class);
        $twig->expects(self::never())->method('render');

        self::assertSame(
            '<a href="/article/' . $naddr . '" class="nostr-link">read article</a>',
            $this->renderNostrLink(
                $twig,
                $naddr,
                [KindsEnum::LONGFORM->value . ':' . $pubkey . ':' . $identifier => $event],
                'read article',
                true,
            ),
        );
    }

    private function exactEvent(int $kind): object
    {
        return (object) [
            'id' => str_repeat('b', 64),
            'kind' => $kind,
            'pubkey' => str_repeat('a', 64),
            'content' => 'Private note body',
            'created_at' => 123,
            'tags' => [['title', 'Private title'], ['content-warning', 'private warning']],
        ];
    }

    /** @param array<string, object> $eventsByNaddr */
    private function renderNostrLink(
        TwigEnvironment $twig,
        string $naddr,
        array $eventsByNaddr,
        ?string $displayText = null,
        bool $preferInline = false,
        ?ArticleFactory $articleFactory = null,
        array $eventsById = [],
        array $metadataByHex = [],
    ): string {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findById');
        $events->expects(self::never())->method('findByNaddr');
        $events->expects(self::never())->method('findByFilter');
        $converter = new Converter(
            $this->createMock(RedisCacheService::class),
            $twig,
            $articleFactory ?? $this->createMock(ArticleFactory::class),
            $this->createMock(\AsciiDocConverter::class),
            $events,
        );

        $method = (new \ReflectionClass(Converter::class))->getMethod('renderNostrLink');

        return $method->invoke(
            $converter,
            new Bech32($naddr),
            $naddr,
            $metadataByHex,
            $eventsById,
            $displayText,
            $preferInline,
            $eventsByNaddr,
        );
    }
}
