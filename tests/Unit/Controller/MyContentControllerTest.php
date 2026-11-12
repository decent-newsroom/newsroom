<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Newsroom\MyContentController;
use App\Entity\Article;
use App\Entity\User;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Repository\PublicationSubdomainSubscriptionRepository;
use App\Service\NewsroomUnfoldNavigationService;
use Doctrine\ORM\EntityManagerInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Attribute\Route;
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

final class MyContentControllerTest extends TestCase
{
    /**
     * @dataProvider roleAccessCases
     *
     * @param list<string>|null $roles
     */
    public function testInventoryRouteRequiresRoleUser(?array $roles, bool $allowed): void
    {
        $method = new \ReflectionMethod(MyContentController::class, 'index');
        $routes = $method->getAttributes(Route::class);
        self::assertCount(1, $routes);
        $route = $routes[0]->newInstance();
        self::assertSame('/my-content', $route->path);
        self::assertSame('my_content', $route->name);

        $storage = new TokenStorage();
        if ($roles !== null) {
            $user = new InMemoryUser('viewer', null, $roles);
            $storage->setToken(new UsernamePasswordToken($user, 'main', $roles));
        }

        $event = new ControllerArgumentsEvent(
            $this->createMock(HttpKernelInterface::class),
            [new MyContentController(), 'index'],
            [],
            Request::create('/my-content'),
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

    /**
     * @dataProvider ownerKeys
     */
    public function testQueriesUseLoggedInOwnerAndKeepSourceArticles(string $ownerHex): void
    {
        $published = $this->article('1', 'Published article', 'published-slug', 'Published summary', '2024-01-01', $ownerHex)
            ->setKind(KindsEnum::LONGFORM)
            ->setPublishedAt(new \DateTimeImmutable('2024-03-01'));
        $untitled = $this->article('2', null, 'slug-as-title', null, '2024-02-01', $ownerHex)
            ->setKind(KindsEnum::LONGFORM);
        $draft = $this->article('3', 'Private draft', 'draft-slug', 'Draft summary', '2024-04-01', $ownerHex)
            ->setKind(KindsEnum::LONGFORM_DRAFT)
            ->setPublishedAt(new \DateTimeImmutable('2030-01-01'));

        $parameters = $this->renderInventory(
            [$published, $untitled],
            [$draft],
            ['pubkey' => str_repeat('f', 64), 'author' => 'another-user'],
            $ownerHex,
        );

        self::assertSame(['all' => 3, 'published' => 2, 'drafts' => 1], $parameters['counts']);
        self::assertSame('all', $parameters['activeType']);
        self::assertSame('recent', $parameters['sort']);
        self::assertSame(3, $parameters['filteredCount']);
        self::assertSame(['drafts', 'published', 'published'], array_column($parameters['contentItems'], 'category'));
        self::assertSame(['Private draft', 'Published article', 'slug-as-title'], array_column($parameters['contentItems'], 'title'));
        self::assertSame([$draft, $published, $untitled], array_column($parameters['contentItems'], 'article'));
        self::assertSame($draft->getCreatedAt(), $parameters['contentItems'][0]['updatedAt']);
        self::assertSame($published->getPublishedAt(), $parameters['contentItems'][1]['updatedAt']);
        self::assertSame($untitled->getCreatedAt(), $parameters['contentItems'][2]['updatedAt']);
        self::assertSame('Draft summary', $parameters['contentItems'][0]['summary']);
        self::assertSame(str_repeat('3', 64), $parameters['contentItems'][0]['article']->getEventId());
        self::assertSame(str_repeat('1', 64), $parameters['contentItems'][1]['article']->getEventId());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ownerKeys(): iterable
    {
        yield 'first owner' => [str_repeat('a', 64)];
        yield 'second owner' => [str_repeat('b', 64)];
    }

    /**
     * @dataProvider searchCases
     *
     * @param list<string> $expectedTitles
     */
    public function testTypeAndSearchFilterRowsWithoutChangingTabCounts(
        string $type,
        string $query,
        array $expectedTitles,
    ): void {
        $ownerHex = str_repeat('a', 64);
        $published = [
            $this->article('1', 'Čaj Nostr', 'post-one', 'Public', '2024-01-01', $ownerHex),
            $this->article('2', 'Ordinary', 'post-two', 'Velvet aurora', '2024-02-01', $ownerHex),
            $this->article('3', 'Another', 'deep-key-note', 'Other', '2024-03-01', $ownerHex),
        ];
        $draft = $this->article('4', 'ČAJ private', 'draft', 'Draft', '2024-04-01', $ownerHex)
            ->setKind(KindsEnum::LONGFORM_DRAFT)
            ->setContent('private-only-body');

        $parameters = $this->renderInventory(
            $published,
            [$draft],
            ['type' => $type, 'q' => $query, 'sort' => 'title'],
            $ownerHex,
        );

        self::assertSame($type, $parameters['activeType']);
        self::assertSame(trim($query), $parameters['searchQuery']);
        self::assertSame('title', $parameters['sort']);
        self::assertSame($expectedTitles, array_column($parameters['contentItems'], 'title'));
        self::assertSame(count($expectedTitles), $parameters['filteredCount']);
        self::assertSame(['all' => 4, 'published' => 3, 'drafts' => 1], $parameters['counts']);
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function searchCases(): iterable
    {
        yield 'case-insensitive title across statuses' => ['all', '  čAj  ', ['Čaj Nostr', 'ČAJ private']];
        yield 'published type' => ['published', 'ČAJ', ['Čaj Nostr']];
        yield 'draft type' => ['drafts', 'ČAJ', ['ČAJ private']];
        yield 'summary' => ['all', '  VELVET  ', ['Ordinary']];
        yield 'slug' => ['all', 'KEY-NOTE', ['Another']];
        yield 'body alone is not searched' => ['all', 'private-only-body', []];
    }

    public function testTitleSortIsNaturalAndInvalidOptionsUseDefaults(): void
    {
        $ownerHex = str_repeat('a', 64);
        $articles = [
            $this->article('1', 'Chapter 10', 'ten', null, '2024-03-01', $ownerHex),
            $this->article('2', 'chapter 2', 'two', null, '2024-01-01', $ownerHex),
            $this->article('3', 'Alpha', 'alpha', null, '2024-02-01', $ownerHex),
        ];

        $byTitle = $this->renderInventory($articles, [], ['sort' => 'title'], $ownerHex);
        self::assertSame(['Alpha', 'chapter 2', 'Chapter 10'], array_column($byTitle['contentItems'], 'title'));

        $defaults = $this->renderInventory(
            $articles,
            [],
            ['type' => 'unsupported', 'sort' => 'unsupported', 'page' => -5],
            $ownerHex,
        );
        self::assertSame('all', $defaults['activeType']);
        self::assertSame('recent', $defaults['sort']);
        self::assertSame(1, $defaults['page']);
        self::assertSame(['Chapter 10', 'Alpha', 'chapter 2'], array_column($defaults['contentItems'], 'title'));
    }

    /**
     * @dataProvider paginationCases
     */
    public function testPaginationUsesFilteredRowsAndClampsPage(
        int $requestedPage,
        int $expectedPage,
        int $expectedSize,
        string $firstTitle,
        string $lastTitle,
    ): void {
        $ownerHex = str_repeat('a', 64);
        $drafts = [];
        for ($number = 1; $number <= 27; ++$number) {
            $drafts[] = $this->article(
                'd',
                sprintf('Draft %02d', $number),
                sprintf('draft-%02d', $number),
                null,
                '2024-01-01',
                $ownerHex,
            )->setKind(KindsEnum::LONGFORM_DRAFT)
                ->setEventId(sprintf('%064x', $number));
        }
        $published = [
            $this->article('1', 'Published A', 'published-a', null, '2024-01-01', $ownerHex),
            $this->article('2', 'Published B', 'published-b', null, '2024-01-01', $ownerHex),
        ];

        $parameters = $this->renderInventory(
            $published,
            $drafts,
            ['type' => 'drafts', 'sort' => 'title', 'page' => $requestedPage],
            $ownerHex,
        );
        $visible = $parameters['contentItems'];

        self::assertSame(['all' => 29, 'published' => 2, 'drafts' => 27], $parameters['counts']);
        self::assertSame(27, $parameters['filteredCount']);
        self::assertSame(2, $parameters['totalPages']);
        self::assertSame($expectedPage, $parameters['page']);
        self::assertCount($expectedSize, $visible);
        self::assertSame($firstTitle, $visible[0]['title']);
        self::assertSame($lastTitle, $visible[array_key_last($visible)]['title']);
        self::assertSame($drafts[($expectedPage - 1) * 25], $visible[0]['article']);
    }

    /**
     * @return iterable<string, array{int, int, int, string, string}>
     */
    public static function paginationCases(): iterable
    {
        yield 'first page' => [1, 1, 25, 'Draft 01', 'Draft 25'];
        yield 'second page' => [2, 2, 2, 'Draft 26', 'Draft 27'];
        yield 'past the end' => [999, 2, 2, 'Draft 26', 'Draft 27'];
        yield 'below the beginning' => [-4, 1, 25, 'Draft 01', 'Draft 25'];
    }

    public function testEmptyResultsStillHaveOnePageAndUnfilteredCounts(): void
    {
        $ownerHex = str_repeat('a', 64);
        $article = $this->article('1', 'Visible article', 'article', null, '2024-01-01', $ownerHex);

        $parameters = $this->renderInventory(
            [$article],
            [],
            ['q' => 'not present', 'page' => 99],
            $ownerHex,
        );

        self::assertSame([], $parameters['contentItems']);
        self::assertSame(0, $parameters['filteredCount']);
        self::assertSame(['all' => 1, 'published' => 1, 'drafts' => 0], $parameters['counts']);
        self::assertSame(1, $parameters['page']);
        self::assertSame(1, $parameters['totalPages']);
    }

    public function testFilteringPrecedesPagination(): void
    {
        $ownerHex = str_repeat('a', 64);
        $articles = [];
        for ($number = 1; $number <= 52; ++$number) {
            $articles[] = $this->article(
                '1',
                sprintf('%s %02d', $number % 2 === 0 ? 'Needle' : 'Other', $number),
                sprintf('article-%02d', $number),
                null,
                '@' . (1700000000 + $number),
                $ownerHex,
            )->setEventId(sprintf('%064x', $number));
        }

        $parameters = $this->renderInventory(
            $articles,
            [],
            ['q' => 'NEEDLE', 'sort' => 'recent', 'page' => 2],
            $ownerHex,
        );

        self::assertSame(['all' => 52, 'published' => 52, 'drafts' => 0], $parameters['counts']);
        self::assertSame(26, $parameters['filteredCount']);
        self::assertSame(2, $parameters['totalPages']);
        self::assertSame(2, $parameters['page']);
        self::assertSame(['Needle 02'], array_column($parameters['contentItems'], 'title'));
        self::assertSame($articles[1], $parameters['contentItems'][0]['article']);
    }

    public function testExactlyTwentyFiveResultsFitOnOnePage(): void
    {
        $ownerHex = str_repeat('a', 64);
        $articles = [];
        for ($number = 1; $number <= 25; ++$number) {
            $articles[] = $this->article(
                '1',
                sprintf('Article %02d', $number),
                sprintf('article-%02d', $number),
                null,
                '2024-01-01',
                $ownerHex,
            )->setEventId(sprintf('%064x', $number));
        }

        $parameters = $this->renderInventory($articles, [], ['sort' => 'title', 'page' => 2], $ownerHex);

        self::assertSame(25, $parameters['filteredCount']);
        self::assertSame(1, $parameters['totalPages']);
        self::assertSame(1, $parameters['page']);
        self::assertCount(25, $parameters['contentItems']);
        self::assertSame('Article 01', $parameters['contentItems'][0]['title']);
        self::assertSame('Article 25', $parameters['contentItems'][24]['title']);
    }

    private function article(
        string $idDigit,
        ?string $title,
        string $slug,
        ?string $summary,
        string $createdAt,
        string $ownerHex,
    ): Article {
        return (new Article())
            ->setEventId(str_repeat($idDigit, 64))
            ->setPubkey($ownerHex)
            ->setKind(KindsEnum::LONGFORM)
            ->setTitle($title)
            ->setSlug($slug)
            ->setSummary($summary)
            ->setCreatedAt(new \DateTimeImmutable($createdAt));
    }

    /**
     * @param Article[] $published
     * @param Article[] $drafts
     * @param array<string, string|int> $query
     * @return array<string, mixed>
     */
    private function renderInventory(array $published, array $drafts, array $query, string $ownerHex): array
    {
        $npub = PublicKey::fromHex($ownerHex)?->toBech32();
        self::assertNotNull($npub);
        $user = new User();
        $user->setNpub($npub);

        $queries = [];
        $repository = $this->createMock(ArticleRepository::class);
        $repository->expects(self::exactly(2))
            ->method('findLatestRevisionsByAuthorAndKind')
            ->willReturnCallback(static function (string $pubkey, KindsEnum $kind) use (&$queries, $published, $drafts): array {
                $queries[] = [$pubkey, $kind];

                return match ($kind) {
                    KindsEnum::LONGFORM => $published,
                    KindsEnum::LONGFORM_DRAFT => $drafts,
                    default => throw new \LogicException('Unexpected inventory kind.'),
                };
            });
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->with(Article::class)->willReturn($repository);

        $subscriptionRepository = $this->createMock(PublicationSubdomainSubscriptionRepository::class);
        $subscriptionRepository->expects(self::once())
            ->method('findActiveValidByNpub')
            ->with($npub)
            ->willReturn([]);
        $navigation = new NewsroomUnfoldNavigationService(
            $subscriptionRepository,
            $this->createMock(EventRepository::class),
            $this->createMock(RouterInterface::class),
        );

        $parameters = null;
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')
            ->willReturnCallback(static function (string $template, array $context) use (&$parameters): string {
                self::assertSame('my_content/index.html.twig', $template);
                $parameters = $context;

                return 'my-content-rendered';
            });
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        $container = new Container();
        $container->set('security.token_storage', $storage);
        $container->set('twig', $twig);
        $controller = new MyContentController();
        $controller->setContainer($container);

        $response = $controller->index($em, new Request($query), $navigation);
        self::assertSame('my-content-rendered', $response->getContent());
        self::assertEqualsCanonicalizing(
            [[$ownerHex, KindsEnum::LONGFORM], [$ownerHex, KindsEnum::LONGFORM_DRAFT]],
            $queries,
        );
        self::assertIsArray($parameters);

        return $parameters;
    }
}
