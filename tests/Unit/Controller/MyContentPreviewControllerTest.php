<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Newsroom\MyContentPreviewController;
use App\Entity\Article;
use App\Entity\Event;
use App\Entity\User;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\AuthoredArticlePreview;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\ContentAuthorAccessPolicy;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

final class MyContentPreviewControllerTest extends TestCase
{
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testRouteIsAuthenticatedGetWithExactIdentity(): void
    {
        $method = new \ReflectionMethod(MyContentPreviewController::class, 'preview');
        $route = $method->getAttributes(Route::class)[0]->newInstance();
        self::assertSame(['GET'], $route->methods);
        self::assertSame('[0-9a-f]{64}', $route->requirements['eventId']);
        self::assertSame('ROLE_USER', $method->getAttributes(IsGranted::class)[0]->newInstance()->attribute);
    }

    /** @dataProvider roleCases */
    public function testFragmentRoleAccess(?array $roles, bool $allowed): void
    {
        $tokens = new TokenStorage();
        if ($roles !== null) {
            $tokens->setToken(new UsernamePasswordToken(
                new \Symfony\Component\Security\Core\User\InMemoryUser('viewer', null, $roles), 'main', $roles,
            ));
        }
        $event = new \Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent(
            $this->createMock(\Symfony\Component\HttpKernel\HttpKernelInterface::class),
            [new MyContentPreviewController(), 'preview'], [], Request::create('/my-content/preview/' . self::ID),
            \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST,
        );
        $listener = new \Symfony\Component\Security\Http\EventListener\IsGrantedAttributeListener(
            new \Symfony\Component\Security\Core\Authorization\AuthorizationChecker(
                $tokens, new \Symfony\Component\Security\Core\Authorization\AccessDecisionManager([
                    new \Symfony\Component\Security\Core\Authorization\Voter\RoleVoter(),
                ]),
            ),
        );
        if (!$allowed) {
            $this->expectException(\Symfony\Component\Security\Core\Exception\AccessDeniedException::class);
        }
        $listener->onKernelControllerArguments($event);
        self::assertTrue($allowed);
    }

    public static function roleCases(): iterable
    {
        yield 'anonymous' => [null, false];
        yield 'wrong role' => [['ROLE_OTHER'], false];
        yield 'owner role' => [['ROLE_USER'], true];
    }

    public function testHugePageClampsBeforeComputingOffset(): void
    {
        $article = $this->article();
        $article->setRaw($this->raw($article));
        $context = $this->invoke($article, query: ['page' => PHP_INT_MAX]);
        self::assertSame(1, $context['page']);
        self::assertSame([], $context['references']);
    }

    public function testSuppressedAuthorCannotLoadPreview(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->invoke($this->article(), restriction: 'author');
    }

    public function testNonArticleKindIsRejected(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->invoke($this->article()->setKind(KindsEnum::PUBLICATION_INDEX), restriction: 'kind');
    }

    /** @dataProvider previewCases */
    public function testOwnedExactPreview(bool $draft, bool $eventFallback, bool $sensitive): void
    {
        $article = $this->article($draft);
        $raw = $this->raw($article);
        $raw['tags'] = [
            ['d', 'article'], ['title', '<Title>'],
            ...array_fill(0, 51, ['e', str_repeat('c', 64)]),
            ['e', '<script>'], ['a'], ['p', str_repeat('d', 64)],
            ...($sensitive ? [['content-warning', 'warning']] : []),
        ];
        $article->setRaw($eventFallback ? null : $raw);
        $event = null;
        if ($eventFallback) {
            $event = new Event();
            $event->setId(self::ID);
            $event->setPubkey(self::OWNER);
            $event->setKind($raw['kind']);
            $event->setCreatedAt(123);
            $event->setTags($raw['tags']);
            $event->setContent('Signed text without generated HTML');
        }
        $context = $this->invoke($article, $event, ['page' => 2]);
        self::assertSame(self::ID, $context['presentation']->eventId);
        self::assertSame($draft ? 30024 : 30023, $context['presentation']->kind);
        self::assertSame('<Title>', $context['presentation']->title);
        self::assertSame($sensitive ? 1 : 2, $context['page']);
        self::assertSame($sensitive ? 1 : 2, $context['totalPages']);
        self::assertSame($sensitive ? [] : [52, 53, 54, 55], array_map(
            static fn ($ref): int => $ref->position, $context['references'],
        ));
    }

    public static function previewCases(): iterable
    {
        yield 'published raw' => [false, false, false];
        yield 'owner draft raw' => [true, false, false];
        yield 'event storage without raw projection' => [true, true, false];
        yield 'sensitive references gated' => [false, false, true];
    }

    /** @dataProvider unavailableCases */
    public function testMissingOrMismatchedRawShowsExplicitUnavailable(string $field): void
    {
        $article = $this->article();
        $raw = $this->raw($article);
        if ($field === 'missing') {
            $raw = null;
        } elseif ($field === 'tags') {
            $raw['tags'] = [['d', 'another-coordinate']];
        } else {
            $raw[$field] = match ($field) {
                'kind' => 30024,
                'content' => [],
                default => 'mismatched',
            };
        }
        $article->setRaw($raw);
        $context = $this->invoke($article);
        self::assertNull($context['presentation']);
        self::assertSame([], $context['references']);
    }

    public static function unavailableCases(): iterable
    {
        foreach (['missing', 'id', 'pubkey', 'kind', 'created_at', 'content', 'tags'] as $field) {
            yield $field => [$field];
        }
    }

    /** @dataProvider restrictedCases */
    public function testRestrictedSourcesAreNotRendered(string $restriction): void
    {
        $article = $restriction === 'foreign' ? null : $this->article();
        $this->expectException(NotFoundHttpException::class);
        $this->invoke($article, restriction: $restriction);
    }

    public static function restrictedCases(): iterable
    {
        yield 'another owner or missing' => ['foreign'];
        yield 'hidden coordinate' => ['hidden'];
        yield 'deleted revision' => ['deleted'];
    }

    /** @dataProvider invalidPages */
    public function testInvalidPageIsRejectedBeforeLookingUpData(mixed $page): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->invoke(null, query: ['page' => $page], invalidPage: true);
    }

    public static function invalidPages(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'array' => [['1']];
        yield 'text' => ['bad'];
    }

    /** @param array<string, mixed> $query
     *  @return array<string, mixed>
     */
    private function invoke(?Article $article, ?Event $event = null, array $query = [], string $restriction = '', bool $invalidPage = false): array
    {
        $articles = $this->createMock(ArticleRepository::class);
        $articles->expects($invalidPage || $restriction === 'author' ? self::never() : self::once())->method('findOneBy')
            ->with(['eventId' => self::ID, 'pubkey' => self::OWNER])->willReturn($article);
        $events = $this->createMock(EventRepository::class);
        $events->method('find')->with(self::ID)->willReturn($event);
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $authorExpectation = $authors->expects($invalidPage ? self::never() : self::once())
            ->method('assertReadable')->with(self::OWNER);
        if ($restriction === 'author') {
            $authorExpectation->willThrowException(new NotFoundHttpException());
        }
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->method('isHidden')->willReturn($restriction === 'hidden');
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->method('isSuppressed')->willReturn($restriction === 'deleted');
        $context = [];
        $twig = $this->createMock(Environment::class);
        $twig->expects($invalidPage || $restriction !== '' ? self::never() : self::once())->method('render')
            ->willReturnCallback(static function (string $template, array $parameters) use (&$context): string {
                self::assertSame('my_content/_preview.html.twig', $template);
                $context = $parameters;
                return 'preview';
            });
        $user = new User();
        $user->setNpub(PublicKey::fromHex(self::OWNER)->toBech32());
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        $container = new Container();
        $container->set('security.token_storage', $tokens);
        $container->set('twig', $twig);
        $controller = new MyContentPreviewController();
        $controller->setContainer($container);
        $response = $controller->preview(
            self::ID, new Request($query), $articles, $events,
            new AuthoredArticlePreview(new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service())),
            new NostrKeyService(), $authors, $deletions, $hidden,
        );
        self::assertSame('preview', $response->getContent());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        return $context;
    }

    private function article(bool $draft = false): Article
    {
        return (new Article())->setEventId(self::ID)->setPubkey(self::OWNER)
            ->setKind($draft ? KindsEnum::LONGFORM_DRAFT : KindsEnum::LONGFORM)
            ->setSlug('article')->setCreatedAt(new \DateTimeImmutable('@123'));
    }

    /** @return array<string, mixed> */
    private function raw(Article $article): array
    {
        return [
            'id' => self::ID, 'pubkey' => self::OWNER, 'kind' => $article->getKind()->value,
            'created_at' => 123, 'tags' => [['d', 'article']], 'content' => 'Signed text without generated HTML',
        ];
    }
}
