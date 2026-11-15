<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components\Organisms;

use App\Entity\Event;
use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\ArticleAccessService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use App\Twig\Components\Molecules\NostrEmbed;
use App\Twig\Components\Atoms\PageHeading;
use App\Twig\Components\Organisms\BookmarkItemResolver;
use Doctrine\ORM\EntityManagerInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\ComponentAttributes;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\Runtime\EscaperRuntime;

final class BookmarkItemResolverTest extends KernelTestCase
{
    private const ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const AUTHOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const RELAY = 'wss://bookmark.example.test';

    public function testLocalEventUsesCompactExactReferenceNotLatestCoordinateOrBody(): void
    {
        $event = $this->event();
        $events = $this->createMock(EventRepository::class);
        // The embed reuses the resolver's local read.
        $events->expects(self::once())->method('findById')->with(self::ID)->willReturn($event);
        $events->expects(self::never())->method('findByNaddr');
        $resolver = $this->resolver($events);
        $resolver->mount();

        $html = $this->render($resolver, $events);
        self::assertStringContainsString('event-preview__title', $html);
        self::assertStringContainsString('Book title', $html);
        self::assertStringContainsString('href="/e/' . (new NostrNip19Service())->encodeNote(self::ID) . '"', $html);
        self::assertStringNotContainsString('private body marker', $html);
        self::assertStringNotContainsString('bookmark-event-card', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
        self::assertStringNotContainsString('data-live-action-param="fetchEvent"', $html);
        $this->assertRemove($html, 'e', self::ID);
    }

    public function testMissingEventStaysExplicitlyFetchableWithoutMountNetworkOrPersistence(): void
    {
        $events = $this->createMock(EventRepository::class);
        // No NostrEmbed (and therefore no second lookup) for a missing e.
        $events->expects(self::once())->method('findById')->with(self::ID)->willReturn(null);
        $resolver = $this->resolver($events);
        $resolver->mount();

        $html = $this->render($resolver, $events);
        self::assertNull($resolver->event);
        self::assertFalse($resolver->fetched);
        self::assertStringContainsString('data-action="live#action"', $html);
        self::assertStringContainsString('data-live-action-param="fetchEvent"', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
        $this->assertRemove($html, 'e', self::ID);
    }

    /** @dataProvider relayFailures */
    public function testExplicitFetchRetainsRelayAndErrorStates(bool $throws, string $message): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->method('findById')->willReturn(null);
        $client = $this->createMock(NostrClient::class);
        $call = $client->expects(self::once())->method('getEventById')->with(self::ID, [self::RELAY]);
        if ($throws) {
            $call->willThrowException(new \RuntimeException('relay unavailable'));
        } else {
            $call->willReturn(null);
        }
        $resolver = $this->resolver($events, $client);
        $resolver->mount();
        $resolver->fetchEvent();

        self::assertTrue($resolver->fetched);
        self::assertSame($message, $resolver->error);
        $html = $this->render($resolver, $events);
        self::assertStringContainsString($message, $html);
        self::assertStringNotContainsString('data-live-action-param="fetchEvent"', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
        $this->assertRemove($html, 'e', self::ID);
    }

    public static function relayFailures(): iterable
    {
        yield 'missing' => [false, 'Event not found on relays'];
        yield 'failed' => [true, 'Failed to fetch event'];
    }

    public function testExplicitSuccessfulFetchPersistsAndThenUsesSharedPreview(): void
    {
        $event = $this->event();
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::exactly(2))->method('findById')->with(self::ID)
            ->willReturn(null);
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::once())->method('getEventById')->with(self::ID, [self::RELAY])
            ->willReturn((object) [
                'id' => self::ID, 'pubkey' => self::AUTHOR, 'kind' => 30040,
                'content' => $event->getContent(), 'tags' => $event->getTags(), 'created_at' => 123,
            ]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (Event $persisted): bool => $persisted->getId() === self::ID
                && $persisted->getTags() === $event->getTags(),
        ));
        $em->expects(self::once())->method('flush');
        $resolver = new BookmarkItemResolver($events, $em, $client, new NullLogger());
        $resolver->type = 'e';
        $resolver->value = self::ID;
        $resolver->relay = self::RELAY;
        $resolver->mount();
        self::assertNull($resolver->event);
        $resolver->fetchEvent();

        self::assertTrue($resolver->fetched);
        self::assertNull($resolver->error);
        $html = $this->render($resolver, $events);
        self::assertStringContainsString('event-preview__title', $html);
        self::assertStringContainsString('Book title', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
        $this->assertRemove($html, 'e', self::ID);
    }

    public function testDatabaseFailureKeepsErrorInsteadOfStartingDeferredFetch(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findById')->willThrowException(new \RuntimeException('DB unavailable'));
        $resolver = $this->resolver($events);
        $resolver->mount();
        $html = $this->render($resolver, $events);

        self::assertStringContainsString('Failed to load event', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
        $this->assertRemove($html, 'e', self::ID);
    }

    /** @dataProvider coordinates */
    public function testCoordinatePreviewPreservesKindIdentifierAndRelay(int $kind, ?string $relay, string $prefix): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findById');
        $events->expects(self::once())->method('findByNaddr')->with($kind, self::AUTHOR, 'book:edition')->willReturn(null);
        $resolver = $this->resolver($events);
        $resolver->type = 'a';
        $resolver->value = $kind . ':' . self::AUTHOR . ':book:edition';
        $resolver->relay = $relay;
        $resolver->mount();
        $html = $this->render($resolver, $events);
        $bech = (new NostrNip19Service())->encodeAddr(self::AUTHOR, 'book:edition', $kind, $relay ? [$relay] : []);
        $decoded = (new NostrNip19Service())->decode($bech);

        self::assertSame('book:edition', $decoded['identifier']);
        self::assertSame(self::AUTHOR, $decoded['pubkey']);
        self::assertSame($relay ? [$relay] : [], $decoded['relays'] ?? []);
        self::assertStringContainsString('data-nostr="' . $bech . '"', $html);
        self::assertStringContainsString('data-nostr--deferred-embed-type-value="naddr"', $html);
        self::assertStringContainsString('href="' . $prefix . $bech . '"', $html);
        $this->assertRemove($html, 'a', $resolver->value);
    }

    public static function coordinates(): iterable
    {
        yield 'article with hint' => [30023, self::RELAY, '/article/'];
        yield 'chapter with hint' => [30041, self::RELAY, '/chapter/'];
        yield 'index without hint' => [30040, null, '/e/'];
    }

    public function testLocalCoordinateUsesSharedCompactPreviewWithHintInNavigation(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findById');
        $events->expects(self::once())->method('findByNaddr')->with(30040, self::AUTHOR, 'book:edition')
            ->willReturn($this->event());
        $resolver = $this->resolver($events);
        $resolver->type = 'a';
        $resolver->value = '30040:' . self::AUTHOR . ':book:edition';
        $resolver->mount();
        $html = $this->render($resolver, $events);
        $bech = (new NostrNip19Service())->encodeAddr(self::AUTHOR, 'book:edition', 30040, [self::RELAY]);

        self::assertStringContainsString('Book title', $html);
        self::assertStringContainsString('event-preview__title', $html);
        self::assertStringContainsString('href="/e/' . $bech . '"', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
        $this->assertRemove($html, 'a', $resolver->value);
    }

    public function testMalformedCoordinateFailsClosedWithoutLookup(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findById');
        $events->expects(self::never())->method('findByNaddr');
        $resolver = $this->resolver($events);
        $resolver->type = 'a';
        $resolver->value = 'invalid-coordinate';
        $resolver->mount();
        $html = $this->render($resolver, $events);

        self::assertStringContainsString('nostr-placeholder-label', $html);
        self::assertStringNotContainsString('href=', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
        $this->assertRemove($html, 'a', $resolver->value);
    }

    /** @dataProvider deniedEvents */
    public function testLocalRestrictedOrWarningEventCannotLeakThroughBookmarkTemplate(string $reason): void
    {
        $event = $this->event();
        if ($reason === 'scoped') {
            $event->setKind(30024);
        } elseif ($reason === 'projection') {
            $event->setKind(30023);
        } elseif ($reason === 'warning') {
            $event->setTags([['d', 'book:edition'], ['title', 'Book title'], ['content-warning', 'secret reason']]);
        }
        $events = $this->createMock(EventRepository::class);
        $events->method('findById')->with(self::ID)->willReturn($event);
        $resolver = $this->resolver($events);
        $resolver->mount();
        // Also exercise the LiveAction's locally-found early return.
        $resolver->fetchEvent();
        $html = $this->render($resolver, $events, $reason === 'author');

        self::assertTrue($resolver->fetched);
        self::assertStringNotContainsString('Book title', $html);
        self::assertStringNotContainsString(self::AUTHOR, $html);
        self::assertStringNotContainsString('private body marker', $html);
        self::assertStringNotContainsString('secret reason', $html);
        self::assertStringNotContainsString('event-preview__id', $html);
        self::assertStringNotContainsString('href=', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
        $this->assertRemove($html, 'e', self::ID);
    }

    public static function deniedEvents(): iterable
    {
        yield 'unauthorized draft' => ['scoped'];
        yield 'longform without exact projection' => ['projection'];
        yield 'suppressed author' => ['author'];
        yield 'signed warning' => ['warning'];
    }

    /** @dataProvider nonEventBookmarks */
    public function testProfilesTopicsAndRemovalRetainTheirOriginalTargets(string $type, string $value): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findById');
        $events->expects(self::never())->method('findByNaddr');
        $resolver = $this->resolver($events);
        $resolver->type = $type;
        $resolver->value = $value;
        $resolver->mount();
        $resolver->fetchEvent(); // Not an event: must remain a no-op.
        $html = $this->render($resolver, $events);

        self::assertFalse($resolver->fetched);
        if ($type === 'p') {
            self::assertStringContainsString((new NostrNip19Service())->encodeNpub($value), $html);
        } else {
            self::assertStringContainsString('href="/topics/' . $value . '"', $html);
            self::assertStringContainsString('>' . $value . '</a>', $html);
        }
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
        $this->assertRemove($html, $type, $value);
    }

    public static function nonEventBookmarks(): iterable
    {
        yield 'profile' => ['p', self::AUTHOR];
        yield 'topic' => ['t', 'nostr'];
    }

    public function testBookmarksPagePreResolvedEventsUseSharedPreviewInOriginalMixedOrder(): void
    {
        $event = $this->event();
        $missing = str_repeat('c', 64);
        $coordinate = '30040:' . self::AUTHOR . ':cold:edition';
        $items = [
            ['e', self::ID], ['p', self::AUTHOR], ['e', $missing],
            ['a', $coordinate], ['t', 'nostr'], ['e', self::ID],
        ];
        $events = $this->createMock(EventRepository::class);
        // Only the cold ref is looked up: both duplicate local rows reuse the batch.
        $events->expects(self::once())->method('findById')->with($missing)->willReturn(null);
        $events->expects(self::once())->method('findByNaddr')->with(30040, self::AUTHOR, 'cold:edition')->willReturn(null);

        $html = $this->renderPage($events, $items, [self::ID => $event]);
        self::assertSame(2, substr_count($html, 'event-preview__title'));
        self::assertSame(2, substr_count($html, 'href="/e/' . (new NostrNip19Service())->encodeNote(self::ID) . '"'));
        self::assertStringNotContainsString('bookmark-event-card', $html);
        self::assertStringNotContainsString('private body marker', $html);
        self::assertStringContainsString('bookmark-items-list mt-3', $html);
        self::assertSame(6, substr_count($html, 'bookmark-item-container d-flex justify-content-between align-items-start gap-2'));
        self::assertSame(6, substr_count($html, 'data-bookmark-list-item'));
        self::assertSame(6, substr_count($html, 'data-action="click->ui--bookmark-list#remove"'));
        preg_match_all('/data-ui--bookmark-list-value-param="([^"]*)"/', $html, $matches);
        self::assertSame(array_column($items, 1), $matches[1]);
        preg_match_all('/data-ui--bookmark-list-type-param="([^"]*)"/', $html, $matches);
        self::assertSame(array_column($items, 0), $matches[1]);
        self::assertStringContainsString((new NostrNip19Service())->encodeNpub(self::AUTHOR), $html);
        self::assertStringContainsString('href="/topics/nostr"', $html);
        self::assertSame(1, substr_count($html, 'data-live-action-param="fetchEvent"'));
        // Only the existing cold coordinate may use a deferred preview.
        self::assertSame(1, substr_count($html, 'data-controller="nostr--deferred-embed"'));
        self::assertStringContainsString((new NostrNip19Service())->encodeAddr(
            self::AUTHOR, 'cold:edition', 30040, [self::RELAY],
        ), $html);
        self::assertStringContainsString('data-ui--bookmark-list-publish-url-value="/api/bookmarks/publish"', $html);
        self::assertStringContainsString('data-ui--bookmark-list-signer-unavailable-message-value=', $html);
        preg_match('/data-ui--bookmark-list-tags-value="([^"]*)"/', $html, $tags);
        self::assertSame(
            array_map(static fn (array $item): array => [...$item, self::RELAY], $items),
            json_decode(html_entity_decode($tags[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR),
        );
        self::assertStringContainsString('data-ui--bookmark-list-content-value="signed&#x20;list&#x20;content"', $html);
        self::assertStringNotContainsString('data-live-action-param="removeBookmark"', $html);
    }

    public function testBookmarksPageBatchReuseDoesNotQueryAgainForDistinctOrDuplicateLocalEvents(): void
    {
        $first = $this->event();
        $second = $this->event();
        $second->setId(str_repeat('d', 64));
        $items = array_merge(
            array_fill(0, 10, ['e', self::ID]),
            array_fill(0, 10, ['e', $second->getId()]),
        );
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findById');
        $events->expects(self::never())->method('findByIds');
        $events->expects(self::never())->method('findByNaddr');
        $html = $this->renderPage($events, $items, [self::ID => $first, $second->getId() => $second]);

        self::assertSame(20, substr_count($html, 'event-preview__title'));
        self::assertSame(20, substr_count($html, 'data-bookmark-list-item'));
        self::assertStringNotContainsString('fetchEvent', $html);
        // Mount-only objects must not spill into HTML attributes/client state.
        self::assertStringNotContainsString('resolvedEvent', $html);
        self::assertStringNotContainsString('private body marker', $html);
    }

    public function testBookmarksPageMismatchedBatchEntryFailsClosedWithoutFallbackQuery(): void
    {
        $wrong = $this->event();
        $wrong->setId(str_repeat('d', 64));
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findById');
        $events->expects(self::never())->method('findByNaddr');
        $html = $this->renderPage($events, [['e', self::ID]], [self::ID => $wrong]);

        self::assertStringContainsString('Failed to load event', $html);
        self::assertStringNotContainsString('Book title', $html);
        self::assertStringNotContainsString('href=', $html);
        self::assertStringNotContainsString('fetchEvent', $html);
        $this->assertRemove($html, 'e', self::ID);
    }

    /** @dataProvider deniedEvents */
    public function testBookmarksPagePreResolvedEventCannotBypassSharedPolicy(string $reason): void
    {
        $event = $this->event();
        if ($reason === 'scoped') {
            $event->setKind(30024);
        } elseif ($reason === 'projection') {
            $event->setKind(30023);
        } elseif ($reason === 'warning') {
            $event->setTags([['d', 'book:edition'], ['title', 'Book title'], ['content-warning', 'secret reason']]);
        }
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findById');
        $events->expects(self::never())->method('findByNaddr');
        $html = $this->renderPage($events, [['e', self::ID]], [self::ID => $event], $reason === 'author');

        foreach (['Book title', self::AUTHOR, 'private body marker', 'secret reason', 'event-preview__id', 'href=', 'nostr--deferred-embed'] as $hidden) {
            self::assertStringNotContainsString($hidden, $html);
        }
        self::assertStringNotContainsString('data-live-action-param="fetchEvent"', $html);
        $this->assertRemove($html, 'e', self::ID);
    }

    public function testBookmarksPageColdEventKeepsExplicitFetchEvenWithoutPreResolvedMap(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findById')->with(self::ID)->willReturn(null);
        $events->expects(self::never())->method('findByNaddr');
        $html = $this->renderPage($events, [['e', self::ID]], []);

        self::assertStringContainsString('data-live-action-param="fetchEvent"', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
        $this->assertRemove($html, 'e', self::ID);
    }

    public function testBookmarksPageDatabaseFailureRetainsExistingErrorAndRemoval(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findById')->with(self::ID)
            ->willThrowException(new \RuntimeException('DB unavailable'));
        $html = $this->renderPage($events, [['e', self::ID]], []);

        self::assertStringContainsString('Failed to load event', $html);
        self::assertStringNotContainsString('data-live-action-param="fetchEvent"', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
        $this->assertRemove($html, 'e', self::ID);
    }

    /** @dataProvider articleEntitlements */
    public function testBookmarksPageExactArticleProjectionStillRequiresEntitlement(bool $exclusive): void
    {
        $event = $this->event();
        $event->setKind(30023);
        $event->setContent('article excerpt marker');
        $article = (new Article())->setEventId(self::ID)->setKind(KindsEnum::LONGFORM)
            ->setPubkey(self::AUTHOR)->setSlug('book:edition')->setCreatedAt(123)
            ->setEssayistExclusive($exclusive);
        $article->setRaw(['tags' => [['d', 'book:edition']]]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findById');
        $events->expects(self::never())->method('findByNaddr');
        $html = $this->renderPage($events, [['e', self::ID]], [self::ID => $event], projection: $article);

        self::assertSame(!$exclusive, str_contains($html, 'Book title'));
        self::assertSame(!$exclusive, str_contains($html, 'href="/e/' . (new NostrNip19Service())->encodeNote(self::ID) . '"'));
        self::assertSame(!$exclusive, str_contains($html, 'article excerpt marker'));
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
        self::assertStringNotContainsString('data-live-action-param="fetchEvent"', $html);
        $this->assertRemove($html, 'e', self::ID);
    }

    public static function articleEntitlements(): iterable
    {
        yield 'public exact projection' => [false];
        yield 'exclusive without entitlement' => [true];
    }

    private function renderPage(EventRepository $events, array $items, array $resolvedEvents, bool $suppressed = false, ?Article $projection = null): string
    {
        $bookmark = (object) [
            'kind' => 10003, 'tags' => array_map(static fn (array $item): array => [...$item, self::RELAY], $items),
            'content' => 'signed list content', 'listType' => 'Bookmarks', 'createdAt' => 123,
            'description' => null, 'image' => null,
            'items' => array_map(static fn (array $item): array => [
                'type' => $item[0], 'value' => $item[1], 'relay' => self::RELAY,
            ], $items),
        ];
        return $this->render($this->resolver($events), $events, $suppressed, [
            'bookmarks' => [$bookmark], 'resolvedEvents' => $resolvedEvents,
        ], $projection);
    }

    private function resolver(EventRepository $events, ?NostrClient $client = null): BookmarkItemResolver
    {
        if ($client === null) {
            $client = $this->createMock(NostrClient::class);
            $client->expects(self::never())->method('getEventById');
        }
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');
        $resolver = new BookmarkItemResolver($events, $em, $client, new NullLogger());
        $resolver->type = 'e';
        $resolver->value = self::ID;
        $resolver->relay = self::RELAY;
        return $resolver;
    }

    private function event(): Event
    {
        $event = new Event();
        $event->setId(self::ID);
        $event->setPubkey(self::AUTHOR);
        $event->setKind(30040);
        $event->setCreatedAt(123);
        $event->setTags([['d', 'book:edition'], ['title', 'Book title']]);
        $event->setContent('private body marker');
        return $event;
    }

    private function assertRemove(string $html, string $type, string $value): void
    {
        self::assertSame(1, substr_count($html, 'data-action="click->ui--bookmark-list#remove"'));
        self::assertStringContainsString('data-ui--bookmark-list-type-param="' . $type . '"', $html);
        self::assertStringContainsString('data-ui--bookmark-list-value-param="' . $value . '"', $html);
        self::assertStringNotContainsString('data-live-action-param="removeBookmark"', $html);
    }

    private function render(BookmarkItemResolver $resolver, EventRepository $events, bool $suppressed = false, ?array $page = null, ?Article $projection = null): string
    {
        self::bootKernel();
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        if ($suppressed) {
            $authors->method('assertReadable')->willThrowException(new NotFoundHttpException());
        }
        $policy = new EventPreviewAccessPolicy(
            $authors,
            $this->createMock(DeletedEventRepository::class),
            $this->createMock(HiddenCoordinateRepository::class),
            new RecordIdentityService(),
            $this->createMock(Security::class),
            $this->createMock(UserMuteListService::class),
            new NostrKeyService(),
            new NullLogger(),
            new ArticleAccessService(new NostrIdentityService(
                $this->createMock(Nip19DecoderInterface::class),
                $this->createMock(Nip19EncoderInterface::class),
            )),
        );
        self::getContainer()->set(EventRepository::class, $events);
        $articles = $this->createMock(ArticleRepository::class);
        if ($projection !== null) {
            $articles->expects(self::once())->method('findOneBy')->with(['eventId' => self::ID])->willReturn($projection);
        }
        self::getContainer()->set(ArticleRepository::class, $articles);
        self::getContainer()->set(EventPreviewAccessPolicy::class, $policy);
        self::getContainer()->set(EventPresentationFactory::class, new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()));
        self::getContainer()->set(LoggerInterface::class, new NullLogger());
        if ($page !== null) {
            $client = $this->createMock(NostrClient::class);
            $client->expects(self::never())->method('getEventById');
            $em = $this->createMock(EntityManagerInterface::class);
            $em->expects(self::never())->method('persist');
            $em->expects(self::never())->method('flush');
            self::getContainer()->set(NostrClient::class, $client);
            self::getContainer()->set(EntityManagerInterface::class, $em);
            $twig = self::getContainer()->get(Environment::class);
            // Exercise the real page body, not the unrelated app-shell/navigation.
            $twig->setLoader(new ChainLoader([
                new ArrayLoader(['reading-nook-layout.html.twig' => '{% block body %}{% endblock %}']),
                $twig->getLoader(),
            ]));
            return $twig->render('pages/my-bookmarks.html.twig', $page);
        }
        return self::getContainer()->get(Environment::class)->render(
            'components/Organisms/BookmarkItemResolver.html.twig',
            get_object_vars($resolver) + ['attributes' => new ComponentAttributes([], new EscaperRuntime())],
        );
    }

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new BookmarkItemResolverTestKernel($options['environment'] ?? 'test', $options['debug'] ?? true);
    }
}

/** Only profile markup is stubbed; bookmark and shared event previews are real. */
#[AsTwigComponent(name: 'Molecules:UserFromNpub', template: 'components/Atoms/Content.html.twig')]
final class BookmarkProfileFixture
{
    public string $parsed = '';

    public function mount(string $ident): void
    {
        $this->parsed = htmlspecialchars($ident, ENT_QUOTES);
    }
}

final class BookmarkItemResolverTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new TwigComponentBundle();
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true, 'secret' => 'bookmark-template-test',
            'property_info' => ['with_constructor_extractor' => true],
        ]);
        $container->import($this->getProjectDir() . '/config/packages/translation.yaml');
        $container->extension('twig', [
            'default_path' => '%kernel.project_dir%/templates', 'strict_variables' => true,
        ]);
        $container->extension('twig_component', [
            'anonymous_template_directory' => 'components/',
            'defaults' => ['App\\Twig\\Components\\' => 'components/'],
        ]);
        $services = $container->services();
        $services->set(\App\Twig\Filters::class)->autowire()->autoconfigure();
        foreach ([EventRepository::class, ArticleRepository::class, EventPreviewAccessPolicy::class, EventPresentationFactory::class, LoggerInterface::class, EntityManagerInterface::class, NostrClient::class] as $dependency) {
            $services->set($dependency)->synthetic()->public();
        }
        $services->set(NostrEmbed::class)->autowire()->autoconfigure();
        // Use the real resolver mount/template; Live HTTP transport is outside this template test.
        $services->set(BookmarkItemResolver::class)->autowire()->tag('twig.component', [
            'key' => 'Organisms:BookmarkItemResolver',
            'template' => 'components/Organisms/BookmarkItemResolver.html.twig',
            'expose_public_props' => true,
        ]);
        $services->set(PageHeading::class)->autowire()->autoconfigure();
        $services->set(BookmarkProfileFixture::class)->autowire()->autoconfigure();
        $services->set(BookmarkTemplateExtension::class)->autowire()->autoconfigure();
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('topics', '/topics/{tag}');
        $routes->add('api_bookmarks_publish', '/api/bookmarks/publish');
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/newsroom-bookmark-template-' . getmypid() . '/' . $this->getEnvironment();
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/newsroom-bookmark-template-' . getmypid() . '/log';
    }
}

final class BookmarkTemplateExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('resolve_nostr_embeds', static fn (string $content): string => $content)];
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('ux_icon', static fn (): string => 'remove icon')];
    }
}
