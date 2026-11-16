<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Reader\ReadingNookController;
use App\Entity\Event;
use App\Entity\UpdateSubscription;
use App\Entity\User;
use App\Enum\KindsEnum;
use App\Enum\UpdateSourceTypeEnum;
use App\Repository\EventRepository;
use App\Repository\DeletedEventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Repository\UpdateSubscriptionRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\EventWorkspace\DriveMountAccess;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Nostr\NostrIdentityService;
use App\Service\Reader\ArticleAccessService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use Psr\Log\NullLogger;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\Voter\RoleVoter;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\EventListener\IsGrantedAttributeListener;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

final class ReadingNookControllerTest extends TestCase
{
    public function testNpubSubscriptionUsesProfileDisplayName(): void
    {
        $pubkey = str_repeat('a', 64);
        self::assertSame('Profile Name', $this->resolveProfileTitle($pubkey, $pubkey, ['name' => 'fallback-name', 'display_name' => 'Profile Name']));
    }

    public function testNpubSubscriptionAcceptsNpubAndFallsBackToProfileName(): void
    {
        $pubkey = str_repeat('b', 64);
        $npub = (static function (string $pubkey): string { return PublicKey::fromHex(strtolower(trim($pubkey)))?->toBech32() ?? throw new \InvalidArgumentException('Not a valid hex pubkey'); })((string) ($pubkey));
        self::assertSame('profile-name', $this->resolveProfileTitle($pubkey, $npub, ['name' => 'profile-name']));
    }

    public function testNpubSubscriptionWithoutProfileNameUsesExistingFallback(): void
    {
        $pubkey = str_repeat('c', 64);
        self::assertNull($this->resolveProfileTitle($pubkey, $pubkey, []));
    }

    public function testDefaultBookmarkListTitleIsBookmarks(): void
    {
        $controller = new ReadingNookController($this->createMock(RedisCacheService::class));
        $method = new \ReflectionMethod($controller, 'defaultTitleForKind');

        self::assertSame('Bookmarks', $method->invoke($controller, KindsEnum::BOOKMARKS->value));
    }

    /**
     * @dataProvider roleAccessCases
     *
     * @param list<string>|null $roles
     */
    public function testInventoryRouteRequiresRoleUser(?array $roles, bool $allowed): void
    {
        $method = new \ReflectionMethod(ReadingNookController::class, 'index');
        $routes = $method->getAttributes(Route::class);
        self::assertCount(1, $routes);
        $route = $routes[0]->newInstance();
        self::assertSame('/reading-nook', $route->path);
        self::assertSame('reading_nook', $route->name);

        $storage = new TokenStorage();
        if ($roles !== null) {
            $user = new InMemoryUser('viewer', null, $roles);
            $storage->setToken(new UsernamePasswordToken($user, 'main', $roles));
        }

        $controller = new ReadingNookController($this->createMock(RedisCacheService::class));
        $event = new ControllerArgumentsEvent(
            $this->createMock(HttpKernelInterface::class),
            [$controller, 'index'],
            [],
            Request::create('/reading-nook'),
            HttpKernelInterface::MAIN_REQUEST,
        );
        $attributes = $event->getAttributes(IsGranted::class);
        self::assertCount(1, $attributes);
        self::assertSame('ROLE_USER', $attributes[0]->attribute);
        if ($attributes[0]->methods !== []) {
            self::assertNotEmpty($route->methods, 'A method-limited role check cannot guard an unrestricted route.');
            foreach ($route->methods as $routeMethod) {
                self::assertContains($routeMethod, $attributes[0]->methods);
            }
        }

        $listener = new IsGrantedAttributeListener(
            new AuthorizationChecker($storage, new AccessDecisionManager([new RoleVoter()])),
        );
        if (!$allowed) {
            $this->expectException(AccessDeniedException::class);
        }

        $listener->onKernelControllerArguments($event);
        self::assertTrue($allowed);
    }

    /**
     * @return iterable<string, array{list<string>|null, bool}>
     */
    public static function roleAccessCases(): iterable
    {
        yield 'anonymous' => [null, false];
        yield 'authenticated without role' => [['ROLE_OTHER'], false];
        yield 'authenticated owner' => [['ROLE_USER'], true];
    }

    public function testDirectAnonymousInvocationRedirectsWithoutLoadingInventory(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with('home', [], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/home');
        $container = new Container();
        $container->set('security.token_storage', new TokenStorage());
        $container->set('router', $router);
        $controller = new ReadingNookController($this->createMock(RedisCacheService::class));
        $controller->setContainer($container);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');
        $subscriptions = $this->createMock(UpdateSubscriptionRepository::class);
        $subscriptions->expects(self::never())->method('findActiveForUser');
        $eventRepository = $this->createMock(EventRepository::class);
        $response = $controller->index(
            new Request(),
            $em,
            $eventRepository,
            $this->driveAccess($eventRepository),
            $subscriptions,
            $this->eventAccess(null),
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/home', $response->getTargetUrl());
    }

    public function testOwnerInventoryDeduplicatesRevisionsAndKeepsActionsAndSections(): void
    {
        $ownerHex = str_repeat('a', 64);
        $otherHex = str_repeat('b', 64);
        $user = $this->userFor($ownerHex);
        $now = time();
        $events = [
            $this->listEvent(1, KindsEnum::BOOKMARKS, $ownerHex, null, $now - 5000, [
                ['title', 'Old bookmarks'],
            ]),
            $this->listEvent(2, KindsEnum::BOOKMARKS, $ownerHex, null, $now - 4000, [
                ['t', ' Rust '], ['t', 'rust'], ['e', 'note-1'], ['e', 'note-1'], ['p', 'alice'],
            ]),
            $this->listEvent(3, KindsEnum::INTERESTS, $ownerHex, null, $now - 3000, [
                ['title', 'Topics'], ['t', 'Science'], ['t', ' SCIENCE '], ['t', 'Maps'],
            ]),
            $this->listEvent(4, KindsEnum::FOLLOW_PACK, $ownerHex, 'people', $now - 2000, [
                ['title', 'Colleagues'], ['p', 'alice'], ['p', 'ALICE'], ['p', 'bob'],
            ]),
            $this->listEvent(5, KindsEnum::PUBLICATION_INDEX, $ownerHex, 'reading', $now - 1000, [
                ['title', 'Reading queue'], ['t', 'Rust'],
                ['a', '30023:' . $ownerHex . ':article'], ['e', 'note-2'], ['e', 'note-2'],
            ]),
            $this->listEvent(6, KindsEnum::PUBLICATION_INDEX, $ownerHex, 'reading', $now - 6000, [
                ['title', 'Old reading queue'],
            ]),
            $this->listEvent(7, KindsEnum::PUBLICATION_INDEX, $ownerHex, 'magazine', $now - 700, [
                ['title', 'Magazine'], ['a', '30040:' . $ownerHex . ':issue'],
            ]),
            $this->listEvent(8, KindsEnum::PUBLICATION_INDEX, $otherHex, 'other', $now - 500, [
                ['title', 'Other user private inventory'],
            ]),
            $this->listEvent(9, KindsEnum::FOLLOWS, $ownerHex, null, $now - 100, [
                ['title', 'Not yet a nook section'],
            ]),
            $this->listEvent(10, KindsEnum::DIRECTORY, $ownerHex, 'directory', $now - 200, [
                ['title', 'Not yet a nook directory'],
            ]),
            $this->listEvent(11, KindsEnum::CURATION_SET, $ownerHex, 'reading', $now - 1500, [
                ['title', 'Curated queue'],
            ]),
        ];
        $subscription = new UpdateSubscription(
            $user,
            UpdateSourceTypeEnum::NIP51_SET,
            '30004:' . $otherHex . ':shared',
            'Saved updates',
        );
        $subscriptionEvents = $this->createMock(EventRepository::class);
        $subscriptionEvents->expects(self::once())
            ->method('findReadingNookSubscriptionTargets')
            ->with([['kind' => KindsEnum::CURATION_SET->value, 'pubkey' => $otherHex, 'dTag' => 'shared']])
            ->willReturn([]);

        $parameters = $this->renderOwnedInventory(
            $user,
            $events,
            ['pubkey' => $otherHex],
            [$subscription],
            $subscriptionEvents,
        );

        self::assertSame(
            ['Saved updates', 'Magazine', 'Reading queue', 'Curated queue', 'Colleagues', 'Topics', 'Bookmarks'],
            array_column($parameters['items'], 'title'),
        );
        self::assertSame(7, $parameters['totalCount']);
        self::assertSame(7, $parameters['filteredCount']);
        $counts = [
            'bookmarks' => 1,
            'interests' => 1,
            'reading_lists' => 3,
            'follow_packs' => 1,
            'drives' => 0,
            'subscriptions' => 1,
        ];
        self::assertSame($counts, $parameters['sectionCounts']);
        self::assertSame($counts, $parameters['filteredSectionCounts']);
        self::assertSame(
            ['Magazine', 'Reading queue', 'Curated queue'],
            array_column($parameters['itemsBySection']['reading_lists'], 'title'),
        );

        $items = array_column($parameters['items'], null, 'title');
        // These are current display facets/counts, not an ordered child-reference API.
        self::assertSame(['rust'], $items['Bookmarks']['tags']);
        self::assertSame(3, $items['Bookmarks']['meta']['itemCount']);
        self::assertNull($items['Bookmarks']['url']);
        self::assertSame('/my_bookmarks', $items['Bookmarks']['manageUrl']);
        self::assertSame(['maps', 'science'], $items['Topics']['tags']);
        self::assertSame(2, $items['Topics']['meta']['topicCount']);
        self::assertSame(2, $items['Colleagues']['meta']['memberCount']);
        self::assertSame('/follow_pack_setup', $items['Colleagues']['manageUrl']);
        self::assertSame(sprintf('%064x', 4), $items['Colleagues']['eventId']);
        self::assertSame(2, $items['Reading queue']['meta']['itemCount']);
        self::assertSame(1, $items['Magazine']['meta']['itemCount']);
        self::assertSame(
            '/reading-list?' . http_build_query(['npub' => $user->getUserIdentifier(), 'slug' => 'reading']),
            $items['Reading queue']['url'],
        );
        self::assertSame('/read_wizard_articles?load=reading', $items['Reading queue']['manageUrl']);
        self::assertSame('/updates_subscriptions', $items['Saved updates']['url']);
        self::assertSame('/updates_subscriptions', $items['Saved updates']['manageUrl']);
        self::assertSame('rust', $parameters['allTags'][0]);
    }

    public function testChangingOwnersDoesNotExposeTheOtherInventory(): void
    {
        $aliceHex = str_repeat('a', 64);
        $bobHex = str_repeat('b', 64);
        $alice = $this->userFor($aliceHex);
        $bob = $this->userFor($bobHex);
        $now = time();
        $events = [
            $this->listEvent(1, KindsEnum::BOOKMARKS, $aliceHex, null, $now - 100, [
                ['title', 'Alice bookmarks'],
            ]),
            $this->listEvent(2, KindsEnum::BOOKMARKS, $bobHex, null, $now - 200, [
                ['title', 'Bob bookmarks'],
            ]),
        ];

        $aliceInventory = $this->renderOwnedInventory($alice, $events, ['pubkey' => $bobHex]);
        $bobInventory = $this->renderOwnedInventory($bob, $events, ['pubkey' => $aliceHex]);

        self::assertSame(['Alice bookmarks'], array_column($aliceInventory['items'], 'title'));
        self::assertSame(['Bob bookmarks'], array_column($bobInventory['items'], 'title'));
        self::assertSame(1, $aliceInventory['totalCount']);
        self::assertSame(1, $bobInventory['totalCount']);
    }

    public function testDriveInventoryFiltersDeniedRootsBeforeCountsAndFacets(): void
    {
        $ownerHex = str_repeat('a', 64);
        $user = $this->userFor($ownerHex);
        $visible = $this->listEvent(7, KindsEnum::DRIVE, $ownerHex, null, time(), [
            ['d', 'visible'],
            ['title', 'Visible drive'],
            ['t', 'visible-topic'],
        ]);
        $denied = $this->listEvent(8, KindsEnum::DRIVE, $ownerHex, null, time() - 1, [
            ['d', 'denied'],
            ['title', 'Secret drive title'],
            ['t', 'secret-topic'],
            ['a', '34550:' . $ownerHex . ':private-community'],
        ]);
        $eventRepository = $this->createMock(EventRepository::class);
        $driveAccess = $this->driveAccess($eventRepository);

        $context = $this->renderOwnedInventory(
            $user,
            [$visible, $denied],
            subscriptionEvents: $eventRepository,
            driveAccess: $driveAccess,
        );

        self::assertSame(1, $context['totalCount']);
        self::assertSame(1, $context['sectionCounts']['drives']);
        self::assertCount(1, $context['items']);
        self::assertSame('Visible drive', $context['items'][0]['title']);
        self::assertContains('visible-topic', $context['allTags']);
        self::assertNotContains('secret-topic', $context['allTags']);
        self::assertStringNotContainsString('Secret drive title', serialize($context));
    }

    public function testSupportedListKindsAppearInTheirCurrentSections(): void
    {
        $ownerHex = str_repeat('a', 64);
        $user = $this->userFor($ownerHex);
        $kinds = [
            [KindsEnum::BOOKMARKS, 'bookmarks'],
            [KindsEnum::BOOKMARK_SETS, 'bookmarks'],
            [KindsEnum::INTERESTS, 'interests'],
            [KindsEnum::INTEREST_SETS, 'interests'],
            [KindsEnum::FOLLOW_PACK, 'follow_packs'],
            [KindsEnum::PUBLICATION_INDEX, 'reading_lists'],
            [KindsEnum::DRIVE, 'drives'],
            [KindsEnum::CURATION_SET, 'reading_lists'],
            [KindsEnum::CURATION_VIDEOS, 'reading_lists'],
            [KindsEnum::CURATION_PICTURES, 'reading_lists'],
        ];
        $events = [];
        foreach ($kinds as $index => [$kind]) {
            $slug = $kind->value >= 30000 ? 'list-' . $kind->value : null;
            $events[] = $this->listEvent(
                $index + 1,
                $kind,
                $ownerHex,
                $slug,
                time() - $index,
                [['title', 'List ' . $kind->value]],
            );
        }

        $parameters = $this->renderOwnedInventory($user, $events);
        $itemsByKind = array_column($parameters['items'], null, 'kind');

        self::assertCount(count($kinds), $itemsByKind);
        foreach ($kinds as [$kind, $section]) {
            self::assertSame($section, $itemsByKind[$kind->value]['section']);
        }
        self::assertSame('drives', $itemsByKind[KindsEnum::DRIVE->value]['section']);
        self::assertSame(
            '/reading_nook_drive_mount?driveId=' . sprintf('%064x', array_search(KindsEnum::DRIVE, array_column($kinds, 0), true) + 1),
            $itemsByKind[KindsEnum::DRIVE->value]['url'],
        );
        self::assertNull($itemsByKind[KindsEnum::DRIVE->value]['manageUrl']);
        self::assertSame([
            'bookmarks' => 2,
            'interests' => 2,
            'reading_lists' => 4,
            'follow_packs' => 1,
            'drives' => 1,
            'subscriptions' => 0,
        ], $parameters['sectionCounts']);
    }

    public function testOlderEventsAreNotLostBehindFourHundredRevisions(): void
    {
        $ownerHex = str_repeat('a', 64);
        $now = time();
        $events = [];
        for ($number = 1; $number <= 399; ++$number) {
            $events[] = $this->listEvent(
                $number,
                KindsEnum::BOOKMARKS,
                $ownerHex,
                null,
                $now - $number,
                [['title', 'Bookmark revision ' . $number]],
            );
        }
        $events[] = $this->listEvent(400, KindsEnum::CURATION_SET, $ownerHex, 'boundary', $now - 400, [
            ['title', 'Boundary list'],
        ]);
        $events[] = $this->listEvent(401, KindsEnum::CURATION_SET, $ownerHex, 'beyond', $now - 401, [
            ['title', 'List beyond current cap'],
        ]);
        $events[] = $this->listEvent(402, KindsEnum::DRIVE, $ownerHex, 'main', $now - 402, [
            ['description', 'My Drive'],
        ]);

        $parameters = $this->renderOwnedInventory($this->userFor($ownerHex), $events);

        self::assertSame(['Bookmark revision 1', 'Boundary list', 'List beyond current cap', 'My Drive'], array_column($parameters['items'], 'title'));
        self::assertSame(4, $parameters['totalCount']);
        self::assertSame(1, $parameters['sectionCounts']['bookmarks']);
        self::assertSame(2, $parameters['sectionCounts']['reading_lists']);
        self::assertSame(1, $parameters['sectionCounts']['drives']);
    }

    public function testPagesAndFiltersCoverAllCurrentOwnedCoordinates(): void
    {
        $owner = str_repeat('a', 64);
        $now = time();
        $events = [];
        for ($i = 1; $i <= 430; ++$i) {
            $events[] = $this->listEvent($i, KindsEnum::CURATION_SET, $owner, 'item-' . $i, $now - $i, [
                ['title', 'Guide ' . $i], ['t', $i >= 401 ? 'older' : 'newer'],
            ]);
        }
        // A newer revision must replace, not add or match the older revision.
        $events[] = $this->listEvent(1000, KindsEnum::CURATION_SET, $owner, 'item-410', $now + 1, [
            ['title', 'Replacement'], ['t', 'replacement'],
        ]);
        // Equal timestamps resolve to the lower event id, on both sides of page boundaries.
        $events[] = $this->listEvent(1001, KindsEnum::CURATION_SET, $owner, 'item-420', $now - 420, [
            ['title', 'Losing tie'], ['t', 'secret-old'],
        ]);
        $events[] = $this->listEvent(1002, KindsEnum::CURATION_SET, str_repeat('b', 64), 'foreign', $now + 2, [
            ['title', 'Other owner'], ['t', 'foreign'],
        ]);
        $user = $this->userFor($owner);

        $first = $this->renderOwnedInventory($user, $events);
        self::assertSame(430, $first['totalCount']);
        self::assertSame(430, $first['filteredCount']);
        self::assertSame(18, $first['totalPages']);
        self::assertCount(25, $first['items']);
        self::assertSame('Replacement', $first['items'][0]['title']);
        self::assertNotContains('secret-old', $first['allTags']);
        self::assertNotContains('foreign', $first['allTags']);

        $last = $this->renderOwnedInventory($user, $events, ['page' => '18']);
        self::assertSame(['Guide 426', 'Guide 427', 'Guide 428', 'Guide 429', 'Guide 430'], array_column($last['items'], 'title'));
        self::assertSame(430, $last['filteredCount']);
        self::assertSame(
            array_column($last['items'], 'title'),
            array_column($this->renderOwnedInventory($user, $events, ['page' => '999'])['items'], 'title'),
        );

        $filtered = $this->renderOwnedInventory($user, $events, [
            'page' => '2', 'q' => 'guide', 'section' => 'reading_lists', 'timespan' => '7d', 'tag' => 'OLDER',
        ]);
        self::assertSame(29, $filtered['filteredCount']);
        self::assertSame(430, $filtered['totalCount']);
        self::assertSame(2, $filtered['totalPages']);
        self::assertSame(['Guide 427', 'Guide 428', 'Guide 429', 'Guide 430'], array_column($filtered['items'], 'title'));
        self::assertContains('replacement', $filtered['allTags']);
        self::assertSame(29, $filtered['filteredSectionCounts']['reading_lists']);
        self::assertSame(430, $filtered['sectionCounts']['reading_lists']);
    }

    public function testMoreThanFourHundredDrivesRemainAuthorizedAndCounted(): void
    {
        $owner = str_repeat('a', 64);
        $now = time();
        $events = [];
        for ($i = 1; $i <= 405; ++$i) {
            $events[] = $this->listEvent($i, KindsEnum::DRIVE, $owner, 'drive-' . $i, $now - $i, [
                ['title', 'Drive ' . $i], ['t', $i === 405 ? 'old-drive' : 'drive'],
            ]);
        }
        // The current denied revision blocks fallback to an older public root.
        $events[] = $this->listEvent(500, KindsEnum::DRIVE, $owner, 'drive-404', $now + 1, [
            ['title', 'Denied'], ['t', 'denied'], ['a', '34550:' . $owner . ':scope'],
        ]);
        $page = $this->renderOwnedInventory($this->userFor($owner), $events, ['page' => '17']);
        self::assertSame(404, $page['totalCount']);
        self::assertSame(404, $page['sectionCounts']['drives']);
        self::assertSame(['Drive 401', 'Drive 402', 'Drive 403', 'Drive 405'], array_column($page['items'], 'title'));
        self::assertContains('old-drive', $page['allTags']);
        self::assertNotContains('denied', $page['allTags']);
    }

    public function testScopedListsDoNotContributeRowsCountsFacetsOrSubscriptionTitles(): void
    {
        $owner = str_repeat('a', 64);
        $user = $this->userFor($owner);
        $now = time();
        $audience = $this->listEvent(1, KindsEnum::PUBLICATION_INDEX, $owner, 'audience', $now, [
            ['title', 'Secret audience'], ['s', '30879:' . $owner . ':members'], ['t', 'secret-audience'],
        ]);
        $community = $this->listEvent(2, KindsEnum::CURATION_SET, $owner, 'community', $now - 1, [
            ['title', 'Secret community'], ['a', '34550:' . $owner . ':community'], ['t', 'secret-community'],
        ]);
        $public = $this->listEvent(3, KindsEnum::PUBLICATION_INDEX, $owner, 'public', $now - 2, [
            ['title', 'Public'], ['a', '30040:' . $owner . ':child'], ['t', 'public'],
        ]);
        $result = $this->renderOwnedInventory($user, [$audience, $community, $public]);
        self::assertSame(['Public'], array_column($result['items'], 'title'));
        self::assertSame(1, $result['totalCount']);
        self::assertSame(1, $result['sectionCounts']['reading_lists']);
        self::assertSame(['public'], $result['allTags']);

        $events = $this->createMock(EventRepository::class);
        $events->method('findReadingNookSubscriptionTargets')->willReturn([
            '30040:' . $owner . ':audience' => $audience,
        ]);
        $subscription = new UpdateSubscription($user, UpdateSourceTypeEnum::PUBLICATION, '30040:' . $owner . ':audience', 'Saved subscription');
        $result = $this->renderOwnedInventory($user, [], subscriptions: [$subscription], subscriptionEvents: $events);
        self::assertSame(['Saved subscription'], array_column($result['items'], 'title'));
        self::assertStringNotContainsString('Secret audience', serialize($result));
    }

    public function testMalformedNonParameterizedDTagBeyondFirstPageDoesNotCrashInventory(): void
    {
        $owner = str_repeat('a', 64);
        $now = time();
        $events = [];
        for ($i = 1; $i <= 30; ++$i) {
            $events[] = $this->listEvent($i, KindsEnum::CURATION_SET, $owner, 'item-' . $i, $now - $i, [
                ['title', 'Item ' . $i],
            ]);
        }
        $events[] = $this->listEvent(31, KindsEnum::BOOKMARKS, $owner, null, $now - 100, [
            ['d', ['malformed']], ['title', 'Bookmarks with malformed identifier'], ['t', 'last'],
        ]);

        $result = $this->renderOwnedInventory($this->userFor($owner), $events);
        self::assertSame(31, $result['totalCount']);
        self::assertCount(25, $result['items']);
        self::assertContains('last', $result['allTags']);
        $lastPage = $this->renderOwnedInventory($this->userFor($owner), $events, ['page' => '2']);
        self::assertSame('Bookmarks with malformed identifier', end($lastPage['items'])['title']);
    }

    public function testDeniedListsAndDrivesNeverContributeToCountsOrFacets(): void
    {
        $owner = str_repeat('a', 64);
        $user = $this->userFor($owner);
        $events = [
            $this->listEvent(1, KindsEnum::CURATION_SET, $owner, 'visible', time(), [['title', 'Visible'], ['t', 'shown']]),
            $this->listEvent(2, KindsEnum::CURATION_SET, $owner, 'deleted', time() - 1, [['title', 'Deleted list'], ['t', 'secret-deleted']]),
            $this->listEvent(3, KindsEnum::DRIVE, $owner, 'hidden', time() - 2, [['title', 'Hidden drive'], ['t', 'secret-hidden']]),
            $this->listEvent(4, KindsEnum::DRIVE, $owner, 'deleted-drive', time() - 3, [['title', 'Deleted drive'], ['t', 'secret-drive']]),
        ];
        $access = $this->eventAccess(
            $user,
            [sprintf('%064x', 2), sprintf('%064x', 4)],
            hidden: ['30042:' . $owner . ':hidden'],
        );
        $result = $this->renderOwnedInventory($user, $events, eventAccess: $access);
        self::assertSame(['Visible'], array_column($result['items'], 'title'));
        self::assertSame(1, $result['totalCount']);
        self::assertSame(0, $result['sectionCounts']['drives']);
        self::assertSame(['shown'], $result['allTags']);
        self::assertStringNotContainsString('secret-', serialize($result));

        foreach ([
            $this->eventAccess($user, muted: [$owner]),
            $this->eventAccess($user, authorDenied: true),
        ] as $deniedAccess) {
            $denied = $this->renderOwnedInventory($user, $events, eventAccess: $deniedAccess);
            self::assertSame(0, $denied['totalCount']);
            self::assertSame([], $denied['allTags']);
            self::assertSame([], $denied['items']);
        }
    }

    public function testPolicyFailuresReturn503WithoutAnyPartialInventory(): void
    {
        $owner = str_repeat('a', 64);
        $user = $this->userFor($owner);
        $events = [];
        for ($i = 1; $i <= 130; ++$i) {
            $events[] = $this->listEvent($i, KindsEnum::CURATION_SET, $owner, 'list-' . $i, time() - $i, [
                ['title', 'Private partial result ' . $i], ['t', 'private-facet'],
            ]);
        }
        foreach ([
            $this->eventAccess($user, muteFailure: true),
            $this->eventAccess($user, batchFailureAt: 2),
        ] as $access) {
            $context = $this->renderOwnedInventory($user, $events, eventAccess: $access, expectUnavailable: true);
            self::assertArrayNotHasKey('items', $context);
            self::assertArrayNotHasKey('totalCount', $context);
            self::assertArrayNotHasKey('allTags', $context);
            self::assertStringNotContainsString('Private partial', serialize($context));
            self::assertStringNotContainsString('private-facet', serialize($context));
        }
    }

    public function testProfileMetadataRequiresAnAuthorizedLocalSignedEvent(): void
    {
        $owner = str_repeat('a', 64);
        $author = str_repeat('b', 64);
        $user = $this->userFor($owner);
        $subscription = new UpdateSubscription($user, UpdateSourceTypeEnum::NPUB, $author, 'Saved author');
        foreach (['missing', 'muted', 'suppressed', 'deleted', 'scope', 'warning'] as $case) {
            $event = $this->listEvent(10, KindsEnum::METADATA, $author, null, time(), match ($case) {
                'scope' => [['h', 'private']],
                'warning' => [['content-warning', 'private warning']],
                default => [],
            }, '{"display_name":"Private profile name"}');
            $repository = $this->createMock(EventRepository::class);
            $repository->method('findReadingNookSubscriptionTargets')->willReturn(
                $case === 'missing' ? [] : ['0:' . $author => $event],
            );
            $access = $this->eventAccess(
                $user,
                $case === 'deleted' ? [$event->getId()] : [],
                $case === 'muted' ? [$author] : [],
                authorDenied: $case === 'suppressed',
            );
            $result = $this->renderOwnedInventory($user, [], subscriptions: [$subscription], subscriptionEvents: $repository, eventAccess: $access);
            self::assertSame(['Saved author'], array_column($result['items'], 'title'), $case);
            self::assertStringNotContainsString('Private profile name', serialize($result), $case);
        }
    }

    public function testSubscriptionTypeCannotExposeAnUnrelatedPrivateKind(): void
    {
        $owner = str_repeat('a', 64);
        $user = $this->userFor($owner);
        $subscription = new UpdateSubscription($user, UpdateSourceTypeEnum::PUBLICATION, '30042:' . $owner . ':private', 'Saved source');
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())->method('findReadingNookSubscriptionTargets')->with([])->willReturn([]);
        $result = $this->renderOwnedInventory($user, [], subscriptions: [$subscription], subscriptionEvents: $repository);
        self::assertSame(['Saved source'], array_column($result['items'], 'title'));
    }

    public function testDriveStoredIdentityCannotCollapseDistinctSignedCoordinates(): void
    {
        $owner = str_repeat('a', 64);
        $now = time();
        $denied = $this->listEvent(1, KindsEnum::DRIVE, $owner, 'first', $now, [
            ['title', 'Denied'], ['a', '34550:' . $owner . ':scope'],
        ]);
        $visible = $this->listEvent(2, KindsEnum::DRIVE, $owner, 'second', $now - 1, [
            ['title', 'Visible'], ['t', 'kept'],
        ]);
        $duplicate = $this->listEvent(3, KindsEnum::DRIVE, $owner, 'third', $now - 2, [
            ['title', 'Duplicate'], ['t', 'excluded'],
        ]);
        foreach ([$denied, $visible, $duplicate] as $event) {
            $event->setDTag('stale-projection');
        }
        $result = $this->renderOwnedInventory($this->userFor($owner), [$denied, $visible, $duplicate]);
        self::assertSame(['Visible', 'Duplicate'], array_column($result['items'], 'title'));
        self::assertSame(2, $result['totalCount']);
        self::assertSame(['kept', 'excluded'], $result['allTags']);
    }

    public function testSubscriptionsAreMergedIntoPagesAndRemainFilterable(): void
    {
        $owner = str_repeat('a', 64);
        $user = $this->userFor($owner);
        $now = time();
        $events = [];
        for ($i = 1; $i <= 25; ++$i) {
            $events[] = $this->listEvent($i, KindsEnum::CURATION_SET, $owner, 'item-' . $i, $now - $i, [
                ['title', 'Item ' . $i],
            ]);
        }
        $subscription = new UpdateSubscription($user, UpdateSourceTypeEnum::NIP51_SET, 'invalid', 'Saved updates');
        (new \ReflectionProperty(UpdateSubscription::class, 'createdAt'))->setValue(
            $subscription,
            (new \DateTimeImmutable())->setTimestamp($now - 24),
        );
        $first = $this->renderOwnedInventory($user, $events, subscriptions: [$subscription]);
        $second = $this->renderOwnedInventory($user, $events, ['page' => '2'], [$subscription]);
        self::assertSame(26, $first['totalCount']);
        self::assertSame(1, $first['sectionCounts']['subscriptions']);
        self::assertSame('Item 24', $first['items'][23]['title']);
        self::assertSame('Saved updates', $first['items'][24]['title']);
        self::assertSame(['Item 25'], array_column($second['items'], 'title'));
        self::assertContains('subscription', $first['allTags']);

        $filtered = $this->renderOwnedInventory($user, $events, [
            'section' => 'subscriptions', 'q' => 'updates', 'tag' => 'subscription',
        ], [$subscription]);
        self::assertSame(['Saved updates'], array_column($filtered['items'], 'title'));
        self::assertSame('/updates_subscriptions', $filtered['items'][0]['manageUrl']);
        self::assertSame(1, $filtered['filteredCount']);
        self::assertSame(26, $filtered['totalCount']);
    }

    public function testEqualTimestampRowsHaveStablePageBoundariesAndSourceOrder(): void
    {
        $owner = str_repeat('a', 64);
        $user = $this->userFor($owner);
        $now = time();
        $events = [];
        for ($i = 60; $i >= 1; --$i) {
            $events[] = $this->listEvent($i, KindsEnum::CURATION_SET, $owner, 'item-' . $i, $now, [
                ['title', 'Item ' . $i],
            ]);
        }
        $events[] = $this->listEvent(61, KindsEnum::DRIVE, $owner, 'drive', $now, [['title', 'Drive']]);
        $subscription = new UpdateSubscription($user, UpdateSourceTypeEnum::NIP51_SET, 'invalid', 'Subscription');
        (new \ReflectionProperty(UpdateSubscription::class, 'createdAt'))->setValue(
            $subscription,
            (new \DateTimeImmutable())->setTimestamp($now),
        );
        $pages = [];
        for ($page = 1; $page <= 3; ++$page) {
            $result = $this->renderOwnedInventory($user, $events, ['page' => (string) $page], [$subscription]);
            $pages[] = array_column($result['items'], 'title');
        }
        self::assertSame(array_map(static fn (int $i): string => 'Item ' . $i, range(1, 25)), $pages[0]);
        self::assertSame(array_map(static fn (int $i): string => 'Item ' . $i, range(26, 50)), $pages[1]);
        self::assertSame([...array_map(static fn (int $i): string => 'Item ' . $i, range(51, 60)), 'Drive', 'Subscription'], $pages[2]);
    }

    public function testCombinedFiltersKeepUnfilteredCountsAndTagFacets(): void
    {
        $ownerHex = str_repeat('a', 64);
        $user = $this->userFor($ownerHex);
        $now = time();
        $day = 86400;
        $events = [
            $this->listEvent(1, KindsEnum::PUBLICATION_INDEX, $ownerHex, 'fresh', $now - $day, [
                ['title', 'Guide fresh'], ['t', ' Rust '], ['t', 'Maps'],
            ]),
            $this->listEvent(2, KindsEnum::CURATION_SET, $ownerHex, 'companion', $now - 2 * $day, [
                ['title', 'Guide companion'], ['t', 'rust'],
            ]),
            $this->listEvent(3, KindsEnum::PUBLICATION_INDEX, $ownerHex, 'wrong-tag', $now - 3 * $day, [
                ['title', 'Guide without tag'], ['t', 'Space'],
            ]),
            $this->listEvent(4, KindsEnum::PUBLICATION_INDEX, $ownerHex, 'old', $now - 8 * $day, [
                ['title', 'Guide old'], ['t', 'Rust'], ['t', 'History'],
            ]),
            $this->listEvent(5, KindsEnum::FOLLOW_PACK, $ownerHex, 'pack', $now - 3600, [
                ['title', 'Guide pack'], ['t', 'Rust'],
            ]),
            $this->listEvent(6, KindsEnum::PUBLICATION_INDEX, $ownerHex, 'unrelated', $now - 7200, [
                ['title', 'Unrelated'], ['t', 'Rust'],
            ]),
        ];

        $parameters = $this->renderOwnedInventory($user, $events, [
            'section' => 'reading_lists',
            'q' => '  GUIDE  ',
            'timespan' => '7d',
            'tag' => ' RUST ',
        ]);

        self::assertSame([
            'q' => 'GUIDE',
            'section' => 'reading_lists',
            'timespan' => '7d',
            'tag' => 'rust',
        ], $parameters['filters']);
        self::assertSame(['Guide fresh', 'Guide companion'], array_column($parameters['items'], 'title'));
        self::assertSame(['Guide fresh', 'Guide companion'], array_column($parameters['itemsBySection']['reading_lists'], 'title'));
        self::assertSame([], $parameters['itemsBySection']['follow_packs']);
        self::assertSame(6, $parameters['totalCount']);
        self::assertSame(2, $parameters['filteredCount']);
        self::assertSame(5, $parameters['sectionCounts']['reading_lists']);
        self::assertSame(1, $parameters['sectionCounts']['follow_packs']);
        self::assertSame(2, $parameters['filteredSectionCounts']['reading_lists']);
        self::assertSame(0, $parameters['filteredSectionCounts']['follow_packs']);
        self::assertSame('rust', $parameters['allTags'][0]);
        self::assertContains('history', $parameters['allTags']);
        self::assertContains('space', $parameters['allTags']);
    }

    /**
     * @dataProvider nookSearchCases
     */
    public function testSearchIncludesListMetadataContentTopicsAndSlug(string $query, bool $matches): void
    {
        $ownerHex = str_repeat('a', 64);
        $event = $this->listEvent(
            1,
            KindsEnum::CURATION_SET,
            $ownerHex,
            'slugneedle',
            time() - 60,
            [['title', 'Titleneedle'], ['summary', 'Summaryneedle'], ['t', 'Topicneedle']],
            'Bodyneedle',
        );
        $parameters = $this->renderOwnedInventory(
            $this->userFor($ownerHex),
            [$event],
            ['q' => '  ' . strtoupper($query) . '  '],
        );

        self::assertSame($matches ? ['Titleneedle'] : [], array_column($parameters['items'], 'title'));
        self::assertSame(1, $parameters['totalCount']);
        self::assertSame($matches ? 1 : 0, $parameters['filteredCount']);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function nookSearchCases(): iterable
    {
        yield 'title' => ['titleneedle', true];
        yield 'summary' => ['summaryneedle', true];
        yield 'body' => ['bodyneedle', true];
        yield 'topic' => ['topicneedle', true];
        yield 'slug' => ['slugneedle', true];
        yield 'no match' => ['missing', false];
    }

    public function testUnknownSectionMatchesNothingAndUnknownTimespanHasNoCutoff(): void
    {
        $ownerHex = str_repeat('a', 64);
        $user = $this->userFor($ownerHex);
        $event = $this->listEvent(
            1,
            KindsEnum::CURATION_SET,
            $ownerHex,
            'old',
            time() - 400 * 86400,
            [['title', 'Old list']],
        );

        $unknownSection = $this->renderOwnedInventory($user, [$event], ['section' => 'unknown']);
        self::assertSame('unknown', $unknownSection['filters']['section']);
        self::assertSame([], $unknownSection['items']);
        self::assertSame(1, $unknownSection['totalCount']);

        $unknownTimespan = $this->renderOwnedInventory($user, [$event], ['timespan' => 'unknown']);
        self::assertSame('unknown', $unknownTimespan['filters']['timespan']);
        self::assertSame(['Old list'], array_column($unknownTimespan['items'], 'title'));
    }

    private function userFor(string $pubkey): User
    {
        $npub = PublicKey::fromHex($pubkey)?->toBech32();
        self::assertNotNull($npub);
        $user = new User();
        $user->setNpub($npub);

        return $user;
    }

    /**
     * @param list<array{string, string}> $tags
     */
    private function listEvent(
        int $id,
        KindsEnum $kind,
        string $pubkey,
        ?string $slug,
        int $createdAt,
        array $tags,
        string $content = '',
    ): Event {
        $event = new Event();
        $event->setId(sprintf('%064x', $id));
        $event->setKind($kind->value);
        $event->setPubkey($pubkey);
        $event->setCreatedAt($createdAt);
        $event->setTags($slug === null ? $tags : [['d', $slug], ...$tags]);
        $event->setDTag($slug);
        $event->setContent($content);
        $event->setSig(str_repeat('f', 128));

        return $event;
    }

    /**
     * The Event query runs against an isolated in-memory database so the owner,
     * kind, and revision-order predicates are exercised rather than mocked.
     *
     * @param Event[] $events
     * @param array<string, string> $query
     * @param UpdateSubscription[] $subscriptions
     * @return array<string, mixed>
     */
    private function renderOwnedInventory(
        User $user,
        array $events,
        array $query = [],
        array $subscriptions = [],
        ?EventRepository $subscriptionEvents = null,
        ?DriveMountAccess $driveAccess = null,
        ?EventPreviewAccessPolicy $eventAccess = null,
        bool $expectUnavailable = false,
    ): array {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The isolated Reading Nook query requires pdo_sqlite.');
        }

        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Entity'], true);
        $config->setNamingStrategy(new \Doctrine\ORM\Mapping\UnderscoreNamingStrategy(CASE_LOWER));
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir());
            $config->setProxyNamespace('Tests\DoctrineProxies');
        }
        $database = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        $metadata = $database->getClassMetadata(Event::class);
        (new SchemaTool($database))->createSchema([$metadata]);
        foreach ($events as $event) {
            $database->persist($event);
        }
        $database->flush();
        $database->clear();

        $em = $this->createMock(EntityManagerInterface::class);
        $subscriptionRepository = $this->createMock(UpdateSubscriptionRepository::class);
        $subscriptionRepository->expects($expectUnavailable ? self::atMost(1) : self::once())
            ->method('findActiveForUser')
            ->with(self::identicalTo($user))
            ->willReturn($subscriptions);

        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturnCallback(static function (string $route, array $parameters = []): string {
            return '/' . $route . ($parameters === [] ? '' : '?' . http_build_query($parameters));
        });
        $parameters = null;
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')
            ->willReturnCallback(static function (string $template, array $context) use (&$parameters, $expectUnavailable): string {
                self::assertSame($expectUnavailable ? 'reader/reading_nook/inventory_error.html.twig' : 'reader/reading_nook/index.html.twig', $template);
                $parameters = $context;

                return 'reading-nook-rendered';
            });
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        $container = new Container();
        $container->set('security.token_storage', $storage);
        $container->set('twig', $twig);
        $container->set('router', $router);
        $cache = $this->createMock(RedisCacheService::class);
        $cache->expects(self::never())->method('getMetadata');
        $controller = new ReadingNookController($cache);
        $controller->setContainer($container);

        $eventRepository = $subscriptionEvents ?? $this->createMock(EventRepository::class);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(Event::class)->willReturn($database);
        $inventoryRepository = new EventRepository($registry);
        $eventRepository->method('iterateReadingNookEvents')
            ->willReturnCallback($inventoryRepository->iterateReadingNookEvents(...));
        $response = $controller->index(
            new Request($query),
            $em,
            $eventRepository,
            $driveAccess ?? $this->driveAccess($eventRepository),
            $subscriptionRepository,
            $eventAccess ?? $this->eventAccess($user),
        );
        self::assertSame('reading-nook-rendered', $response->getContent());
        self::assertSame($expectUnavailable ? 503 : 200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertIsArray($parameters);
        $database->close();

        return $parameters;
    }

    /**
     * @param list<string> $suppressedIds
     * @param list<string> $mutedPubkeys
     * @param list<string> $hiddenCoordinates
     */
    private function driveAccess(
        EventRepository $events,
        array $suppressedIds = [],
        array $mutedPubkeys = [],
        array $hiddenCoordinates = [],
    ): DriveMountAccess {
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->method('isSuppressed')
            ->willReturnCallback(static fn (string $id): bool => in_array($id, $suppressedIds, true));
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->method('findHiddenCoordinates')
            ->willReturnCallback(static fn (): array => $hiddenCoordinates);
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $mutes = $this->createMock(UserMuteListService::class);
        $mutes->method('getMutedPubkeysStrict')->willReturn($mutedPubkeys);

        return new DriveMountAccess($events, $deletions, $hidden, $authors, $mutes, new NullLogger());
    }

    private function eventAccess(
        ?User $viewer,
        array $suppressedIds = [],
        array $muted = [],
        array $hidden = [],
        bool $authorDenied = false,
        bool $muteFailure = false,
        ?int $batchFailureAt = null,
    ): EventPreviewAccessPolicy
    {
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        if ($authorDenied) {
            $authors->method('assertReadable')->willThrowException(new NotFoundHttpException());
            $authors->method('suppressedPubkeys')->willReturnCallback(static fn (array $pubkeys): array => $pubkeys);
        }
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->method('isSuppressed')
            ->willReturnCallback(static fn (string $id): bool => in_array($id, $suppressedIds, true));
        $calls = 0;
        $deletions->method('findSuppressedEventIds')
            ->willReturnCallback(static function (array $events) use ($suppressedIds, $batchFailureAt, &$calls): array {
                if (++$calls === $batchFailureAt) {
                    throw new \RuntimeException('simulated policy failure');
                }
                return array_values(array_filter(
                    array_map(static fn (Event $event): string => $event->getId(), $events),
                    static fn (string $id): bool => in_array($id, $suppressedIds, true),
                ));
            });
        $coordinates = $this->createMock(HiddenCoordinateRepository::class);
        $coordinates->method('isHidden')
            ->willReturnCallback(static fn (string $coordinate): bool => in_array($coordinate, $hidden, true));
        $coordinates->method('findHiddenCoordinates')->willReturn($hidden);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($viewer);
        $mutes = $this->createMock(UserMuteListService::class);
        if ($muteFailure) {
            $mutes->method('getMutedPubkeysStrict')->willThrowException(new \RuntimeException('unavailable'));
        } else {
            $mutes->method('getMutedPubkeysStrict')->willReturn($muted);
        }

        return new EventPreviewAccessPolicy(
            $authors, $deletions, $coordinates, new RecordIdentityService(), $security, $mutes,
            new NostrKeyService(), new NullLogger(),
            new ArticleAccessService(new NostrIdentityService(
                $this->createMock(Nip19DecoderInterface::class),
                $this->createMock(Nip19EncoderInterface::class),
            )),
        );
    }

    private function resolveProfileTitle(string $pubkey, string $sourceValue, array $metadata): ?string
    {
        $controller = new ReadingNookController($this->createMock(RedisCacheService::class));
        $method = new \ReflectionMethod($controller, 'buildSubscriptionEntries');
        $event = $this->listEvent(1, KindsEnum::METADATA, $pubkey, null, time(), [], json_encode($metadata, JSON_THROW_ON_ERROR));
        $events = $this->createMock(EventRepository::class);
        $events->method('findReadingNookSubscriptionTargets')->willReturn(['0:' . $pubkey => $event]);
        $user = $this->userFor(str_repeat('f', 64));
        $subscriptions = $this->createMock(UpdateSubscriptionRepository::class);
        $subscriptions->method('findActiveForUser')->willReturn([
            new UpdateSubscription($user, UpdateSourceTypeEnum::NPUB, $sourceValue),
        ]);
        $container = new Container();
        $router = $this->createMock(RouterInterface::class);
        $router->method('generate')->willReturn('/action');
        $container->set('router', $router);
        $controller->setContainer($container);
        $entries = $method->invoke(
            $controller,
            $user,
            $subscriptions,
            $events,
            $this->eventAccess(null),
            [],
        );
        $title = $entries[0]['title'];

        return str_starts_with($title, 'npub:') ? null : $title;
    }
}
