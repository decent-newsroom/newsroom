<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Update;

use App\Entity\Article;
use App\Entity\Event;
use App\Entity\SubscriptionChange;
use App\Entity\Update;
use App\Entity\UpdateSubscription;
use App\Entity\User;
use App\Enum\UpdateSourceTypeEnum;
use App\Enum\KindsEnum;
use App\Message\FanOutUpdateMessage;
use App\MessageHandler\FanOutUpdateHandler;
use App\Repository\ArticleRepository;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Repository\SubscriptionChangeRepository;
use App\Repository\UpdateRepository;
use App\Repository\UpdateSubscriptionRepository;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\ArticleAccessService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\ReplaceableEventCleanupService;
use App\Service\Update\SubscriptionChangeRecorder;
use App\Service\Update\SubscriptionDiffer;
use App\Service\Update\SubscriptionFeed;
use App\Service\Update\SubscriptionSource;
use App\Service\Update\UpdateMatcher;
use App\Service\Update\UpdateRenderer;
use App\Service\UserMuteListService;
use App\Twig\Components\Molecules\SubscribeButton;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class SubscriptionsTest extends TestCase
{
    private EntityManager $em;
    private EventRepository $events;
    private SubscriptionChangeRepository $changes;
    private UpdateSubscriptionRepository $subscriptions;
    private UpdateRepository $updates;
    private SubscriptionSource $sources;
    private SubscriptionDiffer $differ;
    private SubscriptionChangeRecorder $recorder;
    private ReplaceableEventCleanupService $cleanup;
    private UpdateMatcher $matcher;
    private ManagerRegistry $registry;
    private array $messages = [];

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('Requires pdo_sqlite.');
        }
        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 4) . '/src/Entity'], true);
        $config->setNamingStrategy(new \Doctrine\ORM\Mapping\UnderscoreNamingStrategy(CASE_LOWER));
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir());
            $config->setProxyNamespace('Tests\DoctrineProxies');
        }
        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new SchemaTool($this->em))->createSchema(array_map(
            $this->em->getClassMetadata(...),
            [Event::class, SubscriptionChange::class, User::class, UpdateSubscription::class, Update::class],
        ));
        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->registry->method('getManagerForClass')->willReturn($this->em);
        $this->events = new EventRepository($this->registry);
        $this->changes = new SubscriptionChangeRepository($this->registry);
        $this->subscriptions = new UpdateSubscriptionRepository($this->registry);
        $this->updates = new UpdateRepository($this->registry);
        $nip19 = new NostrNip19Service();
        $this->sources = new SubscriptionSource($nip19, $this->events);
        $this->differ = new SubscriptionDiffer(new EventPresentationFactory(new RecordIdentityService(), $nip19));
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message): Envelope {
            $this->messages[] = $message;
            return new Envelope($message);
        });
        $this->recorder = new SubscriptionChangeRecorder($this->changes, $this->differ, $bus);
        $this->cleanup = new ReplaceableEventCleanupService(new RecordIdentityService(), new NullLogger(), $this->recorder);
        $this->matcher = new UpdateMatcher($this->subscriptions, $this->events, $this->sources, $this->differ);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->close();
        }
    }

    public function testProfileAndAddressInputsShareCanonicalIdentityWithoutLowercasingIdentifiers(): void
    {
        $pubkey = str_repeat('a', 64);
        $nip19 = new NostrNip19Service();
        self::assertSame($pubkey, $this->sources->parse(strtoupper($pubkey))[1]);
        self::assertSame($pubkey, $this->sources->parse('nostr:' . $nip19->encodeNpub($pubkey))[1]);
        $coordinate = '39089:' . $pubkey . ':Mixed:Case';
        self::assertSame($coordinate, $this->sources->parse($coordinate)[1]);
        self::assertSame($coordinate, $this->sources->parse($nip19->encodeAddr($pubkey, 'Mixed:Case', 39089))[1]);
        self::assertSame('3:' . $pubkey . ':', $this->sources->parse('3:' . $pubkey . ':')[1]);
    }

    /** @dataProvider invalidSources */
    public function testInvalidSourcesAreRejected(string $identifier): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->sources->parse($identifier);
    }

    public static function invalidSources(): array
    {
        return [
            [''], ['not a key'], ['npub1invalid'], ['30024:' . str_repeat('a', 64) . ':draft'],
            ['3:' . str_repeat('a', 64) . ':not-replaceable'],
        ];
    }

    public function testDeltasPreserveDuplicateOccurrencesPinsAndKindSpecificMembership(): void
    {
        $coordinate = '30023:' . str_repeat('a', 64) . ':Article';
        $before = [['a', $coordinate], ['a', $coordinate], ['p', str_repeat('b', 64)]];
        $after = [['a', $coordinate], ['a', $coordinate, '', str_repeat('c', 64)]];
        $delta = $this->differ->compare(30040, $before, 'same', $after, 'same');
        self::assertCount(1, $delta['added']);
        self::assertSame(str_repeat('c', 64), $delta['added'][0]['pin']);
        self::assertSame(1, $delta['added'][0]['position']);
        self::assertCount(1, $delta['removed']);
        self::assertSame(1, $delta['removed'][0]['position']);
        self::assertCount(1, $this->differ->members(39089, [
            ['p', str_repeat('b', 64)], ['t', 'topic'], ['a', $coordinate], ['p', 'malformed'],
        ]));
        self::assertSame([], $this->differ->members(10000, [['p', str_repeat('b', 64)]]));
    }

    public function testReorderingIsNotMisreportedAsAdditionsAndRemovals(): void
    {
        $first = ['p', str_repeat('a', 64)];
        $second = ['p', str_repeat('b', 64)];
        $delta = $this->differ->compare(39089, [$first, $second], 'same', [$second, $first], 'same');
        self::assertTrue($delta['reordered']);
        self::assertSame([], $delta['added']);
        self::assertSame([], $delta['removed']);
    }

    public function testCleanupRecordsBaselineThenDeltaBeforeDeletingTheOldRawRevision(): void
    {
        $first = $this->event(1, 39089, 100, [['d', 'pack'], ['p', str_repeat('a', 64)]]);
        $this->cleanup->removeOlderEventVersions($first, $this->em);
        self::assertCount(1, $this->changes->findAll());
        self::assertSame([], $this->messages, 'First observation is a baseline, not fabricated additions.');
        $second = $this->event(2, 39089, 200, [
            ['d', 'pack'], ['p', str_repeat('a', 64)], ['p', str_repeat('b', 64)],
        ]);
        self::assertSame(1, $this->cleanup->removeOlderEventVersions($second, $this->em));
        $this->em->clear();
        self::assertNull($this->events->find($first->getId()));
        $change = $this->changes->find($second->getId());
        self::assertSame($first->getId(), $change->getPreviousEventId());
        self::assertCount(1, $change->getChanges()['added']);
        self::assertCount(2, $this->changes->findAll());
        self::assertCount(1, $this->messages);
        self::assertInstanceOf(FanOutUpdateMessage::class, $this->messages[0]);
    }

    public function testDeploymentCapturesAnExistingPredecessorAndIgnoresStaleAndNoopRevisions(): void
    {
        $first = $this->event(1, 30040, 100, [['d', 'mag'], ['title', 'Before']]);
        $second = $this->event(2, 30040, 200, [['d', 'mag'], ['title', 'After']]);
        $this->recorder->record($second, $this->em);
        self::assertSame($first->getId(), $this->changes->find($second->getId())->getPreviousEventId());
        self::assertCount(1, $this->messages);
        $stale = $this->event(3, 30040, 150, [['d', 'mag'], ['title', 'Out of order']]);
        $this->recorder->record($stale, $this->em);
        self::assertNull($this->changes->find($stale->getId()));
        $noop = $this->event(4, 30040, 300, [['d', 'mag'], ['title', 'After']]);
        $this->recorder->record($noop, $this->em);
        self::assertFalse($this->changes->find($noop->getId())->hasChanges());
        self::assertCount(1, $this->messages);
    }

    public function testEqualTimestampUsesNip01LowerEventIdAndRetriesReuseTheChange(): void
    {
        $loser = $this->event(5, 39089, 100, [['d', 'pack'], ['title', 'Old']]);
        $winner = $this->event(4, 39089, 100, [['d', 'pack'], ['title', 'New']]);
        $this->recorder->record($loser, $this->em);
        self::assertNull($this->changes->find($loser->getId()));
        $this->recorder->record($winner, $this->em);
        $this->recorder->record($winner, $this->em);
        self::assertCount(2, $this->changes->findAll(), 'One predecessor and one immutable change despite retries.');
        self::assertSame($loser->getId(), $this->changes->find($winner->getId())->getPreviousEventId());
    }

    public function testFreeSubscriptionButtonWorksBeyondFiveSourcesAndReusesInactiveRows(): void
    {
        $user = $this->user();
        for ($i = 1; $i <= 6; ++$i) {
            $this->em->persist(new UpdateSubscription($user, UpdateSourceTypeEnum::NPUB, sprintf('%064x', $i)));
        }
        $this->em->flush();
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $button = new SubscribeButton($this->subscriptions, $this->sources, $this->em, $security);
        $button->mount(identifier: '39089:' . str_repeat('b', 64) . ':pack');
        self::assertSame('', $button->error);
        $button->toggle();
        self::assertTrue($button->subscribed);
        self::assertSame(7, $this->subscriptions->countActiveForUser($user));
        $button->subscribed = false; // Browser state never authorizes or determines the mutation.
        $button->toggle();
        self::assertSame(6, $this->subscriptions->countActiveForUser($user));
        $button->toggle();
        self::assertSame(7, $this->subscriptions->countActiveForUser($user));
        self::assertCount(7, $this->subscriptions->findAll());
    }

    public function testProfileButtonMountsFromPubkeyArgument(): void
    {
        $user = $this->user();
        $pubkey = sprintf('%064x', 1);
        $this->em->persist(new UpdateSubscription($user, UpdateSourceTypeEnum::NPUB, $pubkey));
        $this->em->flush();
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $button = new SubscribeButton($this->subscriptions, $this->sources, $this->em, $security);
        $button->mount(pubkey: $pubkey);
        self::assertSame('', $button->error);
        self::assertTrue($button->subscribed);
    }

    public function testArticleOnlyIngestionRecordsAndDeliversWithoutAnEventRow(): void
    {
        $user = $this->user();
        $this->em->persist(new UpdateSubscription($user, UpdateSourceTypeEnum::NPUB, str_repeat('b', 64)));
        $this->em->flush();
        $first = $this->article(80, time() + 10, 'Original');
        $second = $this->article(81, time() + 20, 'Revised');
        $this->cleanup->recordArticleChange($second, $this->em, $first);
        $this->cleanup->recordArticleChange($second, $this->em, $first);
        $change = $this->changes->find($second->getEventId());
        self::assertSame($first->getEventId(), $change->getPreviousEventId());
        self::assertSame(['title'], $change->getChanges()['metadata']);
        self::assertCount(2, $this->changes->findAll());
        self::assertNull($this->events->find($second->getEventId()));
        $access = $this->access($user);
        $handler = new FanOutUpdateHandler(
            $this->events, $this->changes, $this->updates, $this->matcher,
            new UpdateRenderer(new NullLogger()), $this->em, $this->createMock(HubInterface::class),
            new NullLogger(), $access,
        );
        $handler(new FanOutUpdateMessage($second->getEventId()));
        $articles = $this->createMock(ArticleRepository::class);
        $articles->method('findOneBy')->willReturn($second);
        $feed = new SubscriptionFeed(
            $this->updates, $this->events, $articles, $this->changes, $this->sources,
            $access, $this->matcher, new NostrNip19Service(),
        );
        self::assertCount(1, $feed->page($user)['items']);
        self::assertSame('Revised', $feed->page($user)['items'][0]->getTitle());
        self::assertSame(['unread' => 1, 'unseen' => 1], $feed->unreadCounts($user));
        $this->cleanup->recordArticleChange($first, $this->em);
        self::assertCount(2, $this->changes->findAll(), 'Replayed older article revisions do not add history.');
    }

    public function testMagazineChangesResolveArticleOnlyTargetsAndShowTheSubscribedSource(): void
    {
        $user = $this->user();
        $article = $this->article(80, time(), 'An added article');
        $before = $this->event(1, 30040, 100, [['d', 'magazine'], ['title', 'My magazine']]);
        $this->recorder->record($before, $this->em);
        $after = $this->event(2, 30040, 200, [
            ['d', 'magazine'], ['title', 'My magazine'], ['a', '30023:' . $article->getPubkey() . ':article'],
        ]);
        $this->recorder->record($after, $this->em);
        $subscription = new UpdateSubscription($user, UpdateSourceTypeEnum::PUBLICATION, UpdateMatcher::coordinateOf($after));
        $this->em->persist($subscription);
        $this->em->persist(new Update(
            $user, $after->getId(), 30040, $after->getPubkey(), '/e/example', new \DateTimeImmutable(),
            subscription: $subscription, eventCoordinate: UpdateMatcher::coordinateOf($after),
            change: $this->changes->find($after->getId()),
        ));
        $this->em->flush();
        $articles = $this->createMock(ArticleRepository::class);
        $articles->method('findOneBy')->willReturn($article);
        $events = $this->createMock(EventRepository::class);
        $events->method('find')->willReturnCallback(fn ($id) => $this->events->find($id));
        $feed = new SubscriptionFeed(
            $this->updates, $events, $articles, $this->changes, $this->sources,
            $this->access($user), $this->matcher, new NostrNip19Service(),
        );
        $item = $feed->page($user)['items'][0];
        self::assertSame('An added article', $item->getVisibleChanges()['added'][0]['label']);
        self::assertSame('My magazine', $item->getVisibleSource()['label']);
    }

    public function testMagazineSubscriptionMatchesSectionChangesAndRespectsPinsAndCycles(): void
    {
        $user = $this->user();
        $at = time() + 10;
        $section = $this->event(1, 30040, $at, [['d', 'section']]);
        $coordinate = UpdateMatcher::coordinateOf($section);
        $root = $this->event(2, 30040, $at, [['d', 'root'], ['a', $coordinate]]);
        $subscription = new UpdateSubscription($user, UpdateSourceTypeEnum::PUBLICATION, UpdateMatcher::coordinateOf($root));
        $this->em->persist($subscription);
        $this->em->flush();
        self::assertSame([$subscription], $this->matcher->match($section));
        $root->setTags([['d', 'root'], ['a', $coordinate, '', str_repeat('f', 64)]]);
        $this->em->flush();
        self::assertSame([], $this->matcher->match($section));
        $root->setTags([['d', 'root'], ['a', UpdateMatcher::coordinateOf($root)]]);
        $this->em->flush();
        self::assertSame([], $this->matcher->match($section), 'Cycles terminate without a global visited set.');
    }

    public function testFanoutIsIdempotentAndFeedPreservesAllItemsAndReadState(): void
    {
        $user = $this->user();
        $subscription = new UpdateSubscription($user, UpdateSourceTypeEnum::NPUB, str_repeat('b', 64));
        $this->em->persist($subscription);
        $this->em->flush();
        $access = $this->access($user);
        $hub = $this->createMock(HubInterface::class);
        $hub->expects(self::exactly(2))->method('publish')->willReturnCallback(function ($update): string {
            self::assertSame(['type' => 'update'], json_decode($update->getData(), true));
            return 'delivered';
        });
        $handler = new FanOutUpdateHandler(
            $this->events, $this->changes, $this->updates, $this->matcher,
            new UpdateRenderer(new NullLogger()), $this->em, $hub, new NullLogger(), $access,
        );
        foreach ([1, 2] as $i) {
            $event = $this->event($i, 30023, time() + $i, [['d', 'article-' . $i], ['title', 'Article ' . $i]]);
            $this->recorder->record($event, $this->em);
            $handler(new FanOutUpdateMessage($event->getId()));
            $handler(new FanOutUpdateMessage($event->getId()));
        }
        self::assertSame(2, $this->updates->countUnreadForUser($user));
        // Use public index records to exercise feed paging without inventing Article entitlements.
        for ($i = 3; $i <= 54; ++$i) {
            $event = $this->event($i, 30040, time() + $i, [['d', 'index-' . $i], ['title', 'Index ' . $i]]);
            $this->em->persist(new Update($user, $event->getId(), 30040, $event->getPubkey(), '/e/example', new \DateTimeImmutable()));
        }
        $this->em->flush();
        $feed = new SubscriptionFeed(
            $this->updates, $this->events, $this->createMock(ArticleRepository::class),
            $this->changes, $this->sources, $access, $this->matcher, new NostrNip19Service(),
        );
        $page = $feed->page($user);
        self::assertCount(50, $page['items']);
        self::assertNotNull($page['nextCursor']);
        self::assertNull($page['items'][0]->getReadAt(), 'Opening the feed must not mark anything read.');
        self::assertCount(2, $feed->page($user, $page['nextCursor'])['items']);
        self::assertSame(['unread' => 52, 'unseen' => 52], $feed->unreadCounts($user));
    }

    public function testFollowPackDeliversMemberArticlesAndItsOwnChangesWithoutTreatingTopicsAsMembers(): void
    {
        $user = $this->user();
        $at = time() + 10;
        $pack = $this->event(1, 39089, $at, [
            ['d', 'pack'], ['p', str_repeat('c', 64)], ['t', 'not-an-interest-subscription'],
        ]);
        $subscription = new UpdateSubscription($user, UpdateSourceTypeEnum::NIP51_SET, UpdateMatcher::coordinateOf($pack));
        $this->em->persist($subscription);
        $this->em->flush();
        self::assertSame([$subscription], $this->matcher->match($pack));
        $article = $this->event(2, 30023, $at, [['d', 'article'], ['t', 'not-an-interest-subscription']]);
        self::assertSame([], $this->matcher->match($article));
        $article->setPubkey(str_repeat('c', 64));
        $this->em->flush();
        self::assertSame([$subscription], $this->matcher->match($article));
        $this->em->persist(new UpdateSubscription($user, UpdateSourceTypeEnum::NPUB, str_repeat('c', 64)));
        $this->em->flush();
        self::assertCount(1, $this->matcher->match($article), 'Overlapping subscriptions produce one delivery.');
    }

    public function testFeedHidesMutedDeletedAndScopedSourcesFromRowsAndCounts(): void
    {
        $user = $this->user();
        $event = $this->event(1, 30040, time(), [['d', 'index'], ['title', 'Not for this feed']]);
        $this->em->persist(new Update($user, $event->getId(), 30040, $event->getPubkey(), '/e/example', new \DateTimeImmutable()));
        $this->em->flush();
        foreach ([
            $this->access($user, muted: [$event->getPubkey()]),
            $this->access($user, deleted: [$event->getId()]),
            $this->access($user, hidden: [UpdateMatcher::coordinateOf($event)]),
        ] as $access) {
            $feed = $this->feed($access);
            self::assertSame([], $feed->page($user)['items']);
            self::assertSame(['unread' => 0, 'unseen' => 0], $feed->unreadCounts($user));
        }
        $event->setTags([['d', 'index'], ['s', 'private-audience']]);
        $this->em->flush();
        self::assertSame([], $this->feed($this->access($user))->page($user)['items']);
    }

    public function testChangesDoNotRevealRestrictedTargetTitlesOrCountsAndOtherAccountsCannotReadThem(): void
    {
        $user = $this->user();
        $visible = $this->event(1, 30040, 100, [['d', 'visible'], ['title', 'Readable section']]);
        $hidden = $this->event(2, 30040, 100, [['d', 'hidden'], ['title', 'Secret title']]);
        $before = $this->event(3, 30040, 100, [['d', 'root']]);
        $this->recorder->record($before, $this->em);
        $after = $this->event(4, 30040, 200, [
            ['d', 'root'], ['a', UpdateMatcher::coordinateOf($visible)], ['a', UpdateMatcher::coordinateOf($hidden)],
        ]);
        $this->recorder->record($after, $this->em);
        $change = $this->changes->find($after->getId());
        $this->em->persist(new Update(
            $user, $after->getId(), 30040, $after->getPubkey(), '/e/example', new \DateTimeImmutable(),
            change: $change,
        ));
        $this->em->flush();
        $feed = $this->feed($this->access($user, hidden: [UpdateMatcher::coordinateOf($hidden)]));
        $page = $feed->page($user);
        self::assertCount(1, $page['items']);
        $details = $page['items'][0]->getVisibleChanges();
        self::assertCount(1, $details['added']);
        self::assertSame('Readable section', $details['added'][0]['label']);
        self::assertTrue($details['unavailable']);
        self::assertStringNotContainsString('Secret title', json_encode($details));
        $other = new User();
        $other->setNpub((new NostrNip19Service())->encodeNpub(str_repeat('f', 64)));
        $this->em->persist($other);
        $this->em->flush();
        self::assertSame([], $feed->page($other)['items']);
        self::assertSame(['unread' => 0, 'unseen' => 0], $feed->unreadCounts($other));
    }

    private function feed(EventPreviewAccessPolicy $access): SubscriptionFeed
    {
        return new SubscriptionFeed(
            $this->updates, $this->events, $this->createMock(ArticleRepository::class),
            $this->changes, $this->sources, $access, $this->matcher, new NostrNip19Service(),
        );
    }

    private function access(User $user, array $muted = [], array $deleted = [], array $hidden = []): EventPreviewAccessPolicy
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $mutes = $this->createMock(UserMuteListService::class);
        $mutes->method('getMutedPubkeysStrict')->willReturn($muted);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->method('isSuppressed')->willReturnCallback(static fn (string $id): bool => in_array($id, $deleted, true));
        $hiddenCoordinates = $this->createMock(HiddenCoordinateRepository::class);
        $hiddenCoordinates->method('isHidden')->willReturnCallback(static fn (string $coordinate): bool => in_array($coordinate, $hidden, true));
        return new EventPreviewAccessPolicy(
            $this->createMock(ContentAuthorAccessPolicy::class),
            $deletions,
            $hiddenCoordinates,
            new RecordIdentityService(), $security, $mutes, new NostrKeyService(), new NullLogger(),
            new ArticleAccessService(new NostrIdentityService(
                $this->createMock(Nip19DecoderInterface::class),
                $this->createMock(Nip19EncoderInterface::class),
            )),
        );
    }

    private function user(): User
    {
        $user = new User();
        $user->setNpub((new NostrNip19Service())->encodeNpub(str_repeat('a', 64)));
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    private function article(int $id, int $at, string $title): Article
    {
        $article = new Article();
        $article->setEventId(sprintf('%064x', $id));
        $article->setKind(KindsEnum::LONGFORM);
        $article->setPubkey(str_repeat('b', 64));
        $article->setCreatedAt($at);
        $article->setSlug('article');
        $article->setTitle($title);
        $article->setContent('Article body');
        $article->setRaw((object) ['tags' => [['d', 'article'], ['title', $title]]]);
        return $article;
    }

    private function event(int $id, int $kind, int $at, array $tags): Event
    {
        $event = new Event();
        $event->setId(sprintf('%064x', $id));
        $event->setKind($kind);
        $event->setPubkey(str_repeat('b', 64));
        $event->setCreatedAt($at);
        $event->setTags($tags);
        $event->setContent('');
        $event->setSig('');
        $event->extractAndSetDTag();
        $this->em->persist($event);
        $this->em->flush();
        return $event;
    }
}
