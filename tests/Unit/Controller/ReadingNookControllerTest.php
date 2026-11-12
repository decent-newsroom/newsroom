<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Reader\ReadingNookController;
use App\Dto\UserMetadata;
use App\Entity\Event;
use App\Entity\UpdateSubscription;
use App\Entity\User;
use App\Enum\KindsEnum;
use App\Enum\UpdateSourceTypeEnum;
use App\Repository\EventRepository;
use App\Repository\UpdateSubscriptionRepository;
use App\Service\Cache\RedisCacheService;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
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
use Twig\Environment;

final class ReadingNookControllerTest extends TestCase
{
    public function testNpubSubscriptionUsesProfileDisplayName(): void
    {
        $pubkey = str_repeat('a', 64);
        $metadata = new UserMetadata(name: 'fallback-name', displayName: 'Profile Name');
        $controller = $this->controllerWithMetadata($pubkey, $metadata);

        self::assertSame('Profile Name', $this->resolveTitle($controller, $pubkey));
    }

    public function testNpubSubscriptionAcceptsNpubAndFallsBackToProfileName(): void
    {
        $pubkey = str_repeat('b', 64);
        $npub = (static function (string $pubkey): string { return PublicKey::fromHex(strtolower(trim($pubkey)))?->toBech32() ?? throw new \InvalidArgumentException('Not a valid hex pubkey'); })((string) ($pubkey));
        $metadata = new UserMetadata(name: 'profile-name');
        $controller = $this->controllerWithMetadata($pubkey, $metadata);

        self::assertSame('profile-name', $this->resolveTitle($controller, $npub));
    }

    public function testNpubSubscriptionWithoutProfileNameUsesExistingFallback(): void
    {
        $pubkey = str_repeat('c', 64);
        $controller = $this->controllerWithMetadata($pubkey, new UserMetadata());

        self::assertNull($this->resolveTitle($controller, $pubkey));
    }

    public function testDefaultBookmarkListTitleIsBookmarks(): void
    {
        $controller = new ReadingNookController($this->createMock(RedisCacheService::class));
        $method = new \ReflectionMethod($controller, 'defaultTitleForKind');

        self::assertSame('Bookmarks', $method->invoke($controller, KindsEnum::BOOKMARKS->value));
    }

    public function testTocSliceDoesNotChangeNestedIndexMagazineClassification(): void
    {
        $controller = new ReadingNookController($this->createMock(RedisCacheService::class));
        $classify = new \ReflectionMethod($controller, 'looksLikeMagazineIndex');
        $owner = str_repeat('a', 64);
        self::assertTrue($classify->invoke($controller, [['a', '30040:' . $owner . ':nested']]));
        self::assertFalse($classify->invoke($controller, [['a', '30041:' . $owner . ':chapter']]));
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
        $response = $controller->index(
            new Request(),
            $em,
            $this->createMock(EventRepository::class),
            $subscriptions,
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
            ->method('findByNaddr')
            ->with(KindsEnum::CURATION_SET->value, $otherHex, 'shared')
            ->willReturn(null);

        $parameters = $this->renderOwnedInventory(
            $user,
            $events,
            ['pubkey' => $otherHex],
            [$subscription],
            $subscriptionEvents,
        );

        self::assertSame(
            ['Saved updates', 'Reading queue', 'Curated queue', 'Colleagues', 'Topics', 'Bookmarks'],
            array_column($parameters['items'], 'title'),
        );
        self::assertSame(6, $parameters['totalCount']);
        self::assertSame(6, $parameters['filteredCount']);
        $counts = [
            'bookmarks' => 1,
            'interests' => 1,
            'reading_lists' => 2,
            'follow_packs' => 1,
            'subscriptions' => 1,
        ];
        self::assertSame($counts, $parameters['sectionCounts']);
        self::assertSame($counts, $parameters['filteredSectionCounts']);
        self::assertSame(
            ['Reading queue', 'Curated queue'],
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
        self::assertSame([
            'bookmarks' => 2,
            'interests' => 2,
            'reading_lists' => 4,
            'follow_packs' => 1,
            'subscriptions' => 0,
        ], $parameters['sectionCounts']);
    }

    public function testCurrentFourHundredEventCapIsAppliedBeforeDeduplication(): void
    {
        // Characterize the existing limitation; the planned pagination should replace it.
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

        $parameters = $this->renderOwnedInventory($this->userFor($ownerHex), $events);

        self::assertSame(['Bookmark revision 1', 'Boundary list'], array_column($parameters['items'], 'title'));
        self::assertSame(2, $parameters['totalCount']);
        self::assertSame(1, $parameters['sectionCounts']['bookmarks']);
        self::assertSame(1, $parameters['sectionCounts']['reading_lists']);
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
    ): array {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The isolated Reading Nook query requires pdo_sqlite.');
        }

        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Entity'], true);
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

        $repository = new EntityRepository($database, $metadata);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->with(Event::class)->willReturn($repository);
        $subscriptionRepository = $this->createMock(UpdateSubscriptionRepository::class);
        $subscriptionRepository->expects(self::once())
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
            ->willReturnCallback(static function (string $template, array $context) use (&$parameters): string {
                self::assertSame('reader/reading_nook/index.html.twig', $template);
                $parameters = $context;

                return 'reading-nook-rendered';
            });
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        $container = new Container();
        $container->set('security.token_storage', $storage);
        $container->set('twig', $twig);
        $container->set('router', $router);
        $controller = new ReadingNookController($this->createMock(RedisCacheService::class));
        $controller->setContainer($container);

        $response = $controller->index(
            new Request($query),
            $em,
            $subscriptionEvents ?? $this->createMock(EventRepository::class),
            $subscriptionRepository,
        );
        self::assertSame('reading-nook-rendered', $response->getContent());
        self::assertIsArray($parameters);
        $database->close();

        return $parameters;
    }

    private function controllerWithMetadata(string $pubkey, UserMetadata $metadata): ReadingNookController
    {
        $cache = $this->createMock(RedisCacheService::class);
        $cache->expects(self::once())
            ->method('getMetadata')
            ->with($pubkey)
            ->willReturn($metadata);

        return new ReadingNookController($cache);
    }

    private function resolveTitle(ReadingNookController $controller, string $sourceValue): ?string
    {
        $method = new \ReflectionMethod($controller, 'resolveSubscriptionTitle');

        return $method->invoke(
            $controller,
            UpdateSourceTypeEnum::NPUB,
            $sourceValue,
            $this->createMock(EventRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );
    }
}
