<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\DefaultController;
use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Service\EventWorkspace\AuthoredArticlePreview;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrEventVerifier;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Nostr\NostrSigner;
use App\Service\Reader\ArticleAccessService;
use App\Service\Nostr\NostrIdentityService;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Innis\Nostr\Core\Domain\Entity\Event as NostrEvent;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;

final class DefaultControllerNostrPreviewTest extends TestCase
{
    private const EVENT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const AUTHOR = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testMissingNip54WikiPreviewUsesCanonicalNaddrLink(): void
    {
        $pubkey = str_repeat('a', 64);
        $identifier = 'wiki-entry';

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findByNaddr')
            ->with(KindsEnum::WIKI->value, $pubkey, $identifier)
            ->willReturn(null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(Event::class)
            ->willReturn($repository);

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with('nevent', self::arrayHasKey('nevent'), UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/e/wiki-entry');

        $controller = new DefaultController();
        $container = new Container();
        $container->set('router', $router);
        $controller->setContainer($container);

        $method = new \ReflectionMethod($controller, 'handleNaddrPreview');
        $reference = (string) \nostriphant\NIP19\Bech32::naddr(
            kind: KindsEnum::WIKI->value,
            pubkey: $pubkey,
            identifier: $identifier,
        );
        $response = $method->invoke(
            $controller,
            ['kind' => KindsEnum::WIKI->value, 'pubkey' => $pubkey, 'identifier' => $identifier],
            $reference,
            $entityManager,
            $this->createMock(LoggerInterface::class),
            $this->presentations(),
            $this->accessPolicy(),
            $this->articlePreviews(),
            $this->eventVerifier(),
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertStringContainsString('Wiki Preview', $response->getContent());
        self::assertStringContainsString('/e/wiki-entry', $response->getContent());
    }

    public function testStoredNip54WikiPreviewUsesSharedPresentationAndFocusedLink(): void
    {
        $pubkey = str_repeat('b', 64);
        $identifier = 'wiki-entry';
        $wiki = new Event();
        $wiki->setId(self::EVENT_ID);
        $wiki->setKind(KindsEnum::WIKI->value);
        $wiki->setPubkey($pubkey);
        $wiki->setCreatedAt(123);
        $wiki->setTags([['d', $identifier], ['title', 'Wiki Entry'], ['summary', 'A wiki summary']]);

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findByNaddr')
            ->with(KindsEnum::WIKI->value, $pubkey, $identifier)
            ->willReturn($wiki);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(Event::class)
            ->willReturn($repository);

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with('nevent', self::arrayHasKey('nevent'), UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/e/wiki-entry');

        $controller = new class extends DefaultController {
            /** @var array<string, mixed> */
            public array $renderParameters = [];
            public ?string $renderedView = null;

            protected function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                $this->renderedView = $view;
                $this->renderParameters = $parameters;

                return new Response('rendered');
            }
        };
        $container = new Container();
        $container->set('router', $router);
        $controller->setContainer($container);

        $method = new \ReflectionMethod($controller, 'handleNaddrPreview');
        $reference = (string) \nostriphant\NIP19\Bech32::naddr(
            kind: KindsEnum::WIKI->value,
            pubkey: $pubkey,
            identifier: $identifier,
        );
        $response = $method->invoke(
            $controller,
            ['kind' => KindsEnum::WIKI->value, 'pubkey' => $pubkey, 'identifier' => $identifier],
            $reference,
            $entityManager,
            $this->createMock(LoggerInterface::class),
            $this->presentations(),
            $this->accessPolicyForAuthor($pubkey),
            $this->articlePreviews(),
            $this->eventVerifier(),
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('rendered', $response->getContent());
        self::assertSame('event_workspace/_api_event_preview.html.twig', $controller->renderedView);
        self::assertSame('Wiki Entry', $controller->renderParameters['presentation']->title);
        self::assertSame('A wiki summary', $controller->renderParameters['presentation']->description);
        self::assertSame('/e/wiki-entry', $controller->renderParameters['href']);
    }

    public function testSensitiveNip54WikiPreviewUsesWarningOnlyFragment(): void
    {
        $wiki = new Event();
        $wiki->setId(self::EVENT_ID);
        $wiki->setKind(KindsEnum::WIKI->value);
        $wiki->setPubkey(self::AUTHOR);
        $wiki->setCreatedAt(123);
        $wiki->setTags([
            ['d', 'wiki-entry'],
            ['title', 'Private wiki title'],
            ['content-warning', 'Private warning reason'],
        ]);
        $repository = $this->createMock(EventRepository::class);
        $repository->method('findByNaddr')->willReturn($wiki);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Event::class)->willReturn($repository);
        $controller = new class extends DefaultController {
            protected function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                TestCase::assertSame('event_workspace/_api_sensitive_preview.html.twig', $view);
                TestCase::assertSame([], $parameters);

                return new Response('warning-only');
            }
        };

        $response = $this->invokeNaddr(
            ['kind' => KindsEnum::WIKI->value, 'pubkey' => self::AUTHOR, 'identifier' => 'wiki-entry'],
            $entityManager,
            $controller,
            $this->accessPolicyForAuthor(self::AUTHOR),
        );

        self::assertSame('warning-only', $response->getContent());
    }

    public function testNaddrLongformUsesVerifiedSharedPresentationAndFocusedRoute(): void
    {
        $article = $this->article();
        $repository = $this->createMock(ArticleRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['slug' => 'article-slug', 'pubkey' => self::AUTHOR], ['createdAt' => 'DESC', 'eventId' => 'ASC'])
            ->willReturn($article);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(Article::class)
            ->willReturn($repository);
        $controller = new class extends DefaultController {
            public ?string $renderedView = null;
            /** @var array<string, mixed> */
            public array $renderParameters = [];

            protected function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                $this->renderedView = $view;
                $this->renderParameters = $parameters;

                return new Response('shared-article-preview');
            }
        };
        $naddr = (string) \nostriphant\NIP19\Bech32::naddr(
            kind: KindsEnum::LONGFORM->value,
            pubkey: self::AUTHOR,
            identifier: 'article-slug',
        );
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with('article-naddr', ['naddr' => $naddr], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/article/' . $naddr);
        $container = new Container();
        $container->set('router', $router);
        $controller->setContainer($container);

        $response = $this->invokeNaddr(
            ['kind' => KindsEnum::LONGFORM->value, 'pubkey' => self::AUTHOR, 'identifier' => 'article-slug'],
            $entityManager,
            $controller,
        );

        self::assertSame('shared-article-preview', $response->getContent());
        self::assertSame('event_workspace/_api_event_preview.html.twig', $controller->renderedView);
        self::assertSame('Article title', $controller->renderParameters['presentation']->title);
        self::assertSame('/article/' . $naddr, $controller->renderParameters['href']);
    }

    public function testNaddrLongformRejectsInvalidSignature(): void
    {
        $article = $this->article();
        $repository = $this->createMock(ArticleRepository::class);
        $repository->method('findOneBy')->willReturn($article);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Article::class)->willReturn($repository);

        $response = $this->invokeNaddr(
            ['kind' => KindsEnum::LONGFORM->value, 'pubkey' => self::AUTHOR, 'identifier' => 'article-slug'],
            $entityManager,
            eventVerifier: $this->eventVerifier(false),
        );

        self::assertStringContainsString('Event not found locally.', $response->getContent());
    }

    public function testNaddrLongformRejectsSignedEnvelopeThatDoesNotMatchProjection(): void
    {
        $article = $this->article();
        $raw = $this->signedArticleRaw([['d', 'different-slug'], ['title', 'Untrusted title']]);
        $article->setEventId($raw['id']);
        $article->setRaw($raw);
        $repository = $this->createMock(ArticleRepository::class);
        $repository->method('findOneBy')->willReturn($article);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Article::class)->willReturn($repository);

        $response = $this->invokeNaddr(
            ['kind' => KindsEnum::LONGFORM->value, 'pubkey' => self::AUTHOR, 'identifier' => 'article-slug'],
            $entityManager,
        );

        self::assertStringContainsString('Event not found locally.', $response->getContent());
    }

    public function testNaddrPreviewRejectsDecodedIdentityThatConflictsWithRequestedIdentifier(): void
    {
        $requested = (string) \nostriphant\NIP19\Bech32::naddr(
            kind: KindsEnum::LONGFORM->value,
            pubkey: self::AUTHOR,
            identifier: 'article-slug',
        );
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getRepository');
        $requestStack = new RequestStack();
        $requestStack->push(new Request(content: json_encode([
            'identifier' => $requested,
            'type' => 'naddr',
            'decoded' => [
                'bech' => $requested,
                'kind' => KindsEnum::LONGFORM->value,
                'pubkey' => str_repeat('c', 64),
                'identifier' => 'attacker-selected-article',
            ],
        ], JSON_THROW_ON_ERROR)));

        $response = (new DefaultController())->nostrPreview(
            $requestStack,
            $entityManager,
            $this->createMock(LoggerInterface::class),
            $this->presentations(),
            $this->accessPolicy(),
            $this->articlePreviews(),
            $this->eventVerifier(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Invalid address identifier.', $response->getContent());
    }

    /** @dataProvider profileIdentifiers */
    public function testProfilePreviewUsesCanonicalNip19IdentityAndSharedProfileCard(string $type, string $identifier): void
    {
        $controller = new class extends DefaultController {
            public ?string $renderedView = null;
            /** @var array<string, mixed> */
            public array $renderParameters = [];

            protected function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                $this->renderedView = $view;
                $this->renderParameters = $parameters;

                return new Response('profile-preview');
            }
        };
        $requestStack = new RequestStack();
        $requestStack->push(new Request(content: json_encode([
            'identifier' => $identifier,
            'type' => $type,
            'decoded' => ['hex' => str_repeat('c', 64)],
        ], JSON_THROW_ON_ERROR)));

        $response = $controller->nostrPreview(
            $requestStack,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->presentations(),
            $this->accessPolicy(),
            $this->articlePreviews(),
            $this->eventVerifier(),
        );

        self::assertSame('profile-preview', $response->getContent());
        self::assertSame('components/Molecules/ProfilePreview.html.twig', $controller->renderedView);
        self::assertSame(self::AUTHOR, $controller->renderParameters['pubkey']);
        self::assertSame((new NostrNip19Service())->encodeNpub(self::AUTHOR), $controller->renderParameters['npub']);
    }

    public static function profileIdentifiers(): iterable
    {
        $nip19 = new NostrNip19Service();

        yield 'npub' => ['npub', $nip19->encodeNpub(self::AUTHOR)];
        yield 'nprofile' => ['nprofile', (string) \nostriphant\NIP19\Bech32::nprofile(pubkey: self::AUTHOR)];
    }

    public function testColdNaddrLongformKeepsExistingFetchAndViewLink(): void
    {
        $repository = $this->createMock(ArticleRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['slug' => 'article-slug', 'pubkey' => self::AUTHOR], ['createdAt' => 'DESC', 'eventId' => 'ASC'])
            ->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(Article::class)
            ->willReturn($repository);
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with('article-naddr', self::arrayHasKey('naddr'), UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/article/article-slug');
        $controller = new DefaultController();
        $container = new Container();
        $container->set('router', $router);
        $controller->setContainer($container);

        $response = $this->invokeNaddr(
            ['kind' => KindsEnum::LONGFORM->value, 'pubkey' => self::AUTHOR, 'identifier' => 'article-slug'],
            $entityManager,
            $controller,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString("This article hasn't been fetched yet.", $response->getContent());
        self::assertStringContainsString('/article/article-slug', $response->getContent());
    }

    public function testNaddrEssayistExclusiveArticleIsHiddenFromAnonymousViewer(): void
    {
        $article = $this->article()->setEssayistExclusive(true);
        $repository = $this->createMock(ArticleRepository::class);
        $repository->method('findOneBy')->willReturn($article);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Article::class)->willReturn($repository);

        $response = $this->invokeNaddr(
            ['kind' => KindsEnum::LONGFORM->value, 'pubkey' => self::AUTHOR, 'identifier' => 'article-slug'],
            $entityManager,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Event not found locally.', $response->getContent());
    }

    /** @dataProvider articleWarningTags */
    public function testArticleRawWarningEvidenceNeverReachesSpecialist(array $tags): void
    {
        $article = $this->article()->setTitle('Private title')->setSummary('Private summary');
        $signedRaw = $this->signedArticleRaw([['d', 'article-slug'], ...$tags]);
        $article->setEventId($signedRaw['id']);
        $article->setRaw([...$signedRaw, 'sensitive' => false]);
        $repository = $this->createMock(ArticleRepository::class);
        $repository->method('findOneBy')->willReturn($article);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Article::class)->willReturn($repository);
        $controller = new class extends DefaultController {
            protected function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                TestCase::assertSame('event_workspace/_api_sensitive_preview.html.twig', $view);
                TestCase::assertSame([], $parameters, 'No Article or sensitive metadata crosses the template boundary.');

                return new Response('warning-only');
            }
        };

        $response = $this->invokeNaddr(
            ['kind' => 30023, 'pubkey' => self::AUTHOR, 'identifier' => 'article-slug'],
            $entityManager,
            $controller,
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('warning-only', $response->getContent());
    }

    public static function articleWarningTags(): iterable
    {
        yield 'empty content warning' => [[['content-warning']]];
        yield 'warning reason' => [[['content-warning', 'Private reason']]];
        yield 'namespace' => [[['L', 'content-warning']]];
        yield 'qualified label' => [[['l', 'Private label', 'nsfw']]];
        yield 'sensitive hashtag' => [[['t', 'NSFW']]];
    }

    /** @dataProvider articleFirstDCases */
    public function testArticleRawFirstDControlsExposure(?array $raw, string $identifier, bool $readable): void
    {
        $article = $this->article()->setSlug($identifier);
        if ($raw !== null && is_array($raw['tags'] ?? null)) {
            $tags = $raw['tags'];
            if ($this->areStringTags($tags)) {
                $raw = $this->signedArticleRaw($tags);
                $article->setEventId($raw['id']);
            }
        }
        $article->setRaw($raw);
        $repository = $this->createMock(ArticleRepository::class);
        $repository->method('findOneBy')->willReturn($article);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Article::class)->willReturn($repository);
        $controller = new class extends DefaultController {
            public ?string $renderedView = null;
            /** @var array<string, mixed> */
            public array $renderParameters = [];

            protected function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                $this->renderedView = $view;
                $this->renderParameters = $parameters;

                return new Response('shared-article-preview');
            }
        };
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/article/article-slug');
        $container = new Container();
        $container->set('router', $router);
        $controller->setContainer($container);

        $response = $this->invokeNaddr(
            ['kind' => 30023, 'pubkey' => self::AUTHOR, 'identifier' => $identifier],
            $entityManager,
            $controller,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($readable, $controller->renderedView === 'event_workspace/_api_event_preview.html.twig');
        self::assertStringContainsString($readable ? 'shared-article-preview' : 'Event not found locally.', $response->getContent());
    }

    public static function articleFirstDCases(): iterable
    {
        yield 'duplicate projection slug differs from first d' => [['tags' => [['d', 'other'], ['d', 'article-slug']]], 'article-slug', false];
        yield 'malformed first d followed by matching d' => [['tags' => [['d', 42], ['d', 'article-slug']]], 'article-slug', false];
        yield 'missing first d value' => [['tags' => [['d'], ['d', 'article-slug']]], 'article-slug', false];
        yield 'missing raw' => [null, 'article-slug', false];
        yield 'explicit empty d' => [['tags' => [['d', '']]], '', true];
        yield 'missing d is not empty' => [['tags' => []], '', false];
        yield 'valid first d ignores later duplicate' => [['tags' => [['d', 'article-slug'], ['d', 'other']]], 'article-slug', true];
    }

    /** @dataProvider mismatchedNaddrTargets */
    public function testMismatchedLocalTargetIsNotRendered(int $kind, string $mismatch): void
    {
        $target = $this->event($kind, [['d', 'requested']]);
        match ($mismatch) {
            'kind' => $target->setKind(1),
            'author' => $target->setPubkey(str_repeat('c', 64)),
            'later d' => $target->setTags([['d', 'other'], ['d', 'requested']]),
            'malformed first d' => $target->setTags([['d', 42], ['d', 'requested']]),
            'missing d' => $target->setTags([]),
        };
        $repository = $this->createMock(EventRepository::class);
        $repository->method('findByNaddr')->willReturn($target);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Event::class)->willReturn($repository);

        $response = $this->invokeNaddr(
            ['kind' => $kind, 'pubkey' => self::AUTHOR, 'identifier' => 'requested'],
            $entityManager,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Event not found locally.', $response->getContent());
    }

    public static function mismatchedNaddrTargets(): iterable
    {
        foreach ([30040, 30041, 30818] as $kind) {
            foreach (['kind', 'author', 'later d', 'malformed first d', 'missing d'] as $mismatch) {
                yield "$kind $mismatch" => [$kind, $mismatch];
            }
        }
    }

    public function testNaddrChapterDeniedByEventVisibilityPolicyUsesMissingFragment(): void
    {
        $chapter = $this->event(KindsEnum::PUBLICATION_CONTENT->value, [['d', 'chapter-slug']]);
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findByNaddr')
            ->with(KindsEnum::PUBLICATION_CONTENT->value, self::AUTHOR, 'chapter-slug')
            ->willReturn($chapter);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Event::class)->willReturn($repository);

        $response = $this->invokeNaddr(
            ['kind' => KindsEnum::PUBLICATION_CONTENT->value, 'pubkey' => self::AUTHOR, 'identifier' => 'chapter-slug'],
            $entityManager,
            eventPreviewAccess: $this->accessPolicy(false),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Event not found locally.', $response->getContent());
    }

    public function testReadableNaddrChapterUsesSharedPresentationAndFocusedRoute(): void
    {
        $chapter = $this->event(KindsEnum::PUBLICATION_CONTENT->value, [
            ['d', 'chapter-slug'],
            ['title', 'Chapter title'],
            ['summary', 'Chapter summary'],
        ]);
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findByNaddr')
            ->with(KindsEnum::PUBLICATION_CONTENT->value, self::AUTHOR, 'chapter-slug')
            ->willReturn($chapter);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Event::class)->willReturn($repository);
        $naddr = (string) \nostriphant\NIP19\Bech32::naddr(
            kind: KindsEnum::PUBLICATION_CONTENT->value,
            pubkey: self::AUTHOR,
            identifier: 'chapter-slug',
        );
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->expects(self::once())
            ->method('generate')
            ->with('chapter', ['naddr' => $naddr], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/chapter/' . $naddr);
        $controller = new class extends DefaultController {
            public ?string $renderedView = null;
            /** @var array<string, mixed> */
            public array $renderParameters = [];

            protected function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                $this->renderedView = $view;
                $this->renderParameters = $parameters;

                return new Response('shared-chapter-preview');
            }
        };
        $container = new Container();
        $container->set('router', $router);
        $controller->setContainer($container);

        $response = $this->invokeNaddr(
            ['kind' => KindsEnum::PUBLICATION_CONTENT->value, 'pubkey' => self::AUTHOR, 'identifier' => 'chapter-slug'],
            $entityManager,
            $controller,
        );

        self::assertSame('shared-chapter-preview', $response->getContent());
        self::assertSame('event_workspace/_api_event_preview.html.twig', $controller->renderedView);
        self::assertSame('Chapter title', $controller->renderParameters['presentation']->title);
        self::assertSame('Chapter summary', $controller->renderParameters['presentation']->description);
        self::assertSame('/chapter/' . $naddr, $controller->renderParameters['href']);
    }

    public function testSensitiveNaddrChapterReturnsWarningOnlyFragment(): void
    {
        $chapter = $this->event(KindsEnum::PUBLICATION_CONTENT->value, [
            ['d', 'chapter-slug'],
            ['title', 'Private chapter title'],
            ['content-warning', 'Private warning reason'],
        ]);
        $repository = $this->createMock(EventRepository::class);
        $repository->method('findByNaddr')->willReturn($chapter);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Event::class)->willReturn($repository);
        $controller = new class extends DefaultController {
            protected function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                TestCase::assertSame('event_workspace/_api_sensitive_preview.html.twig', $view);
                TestCase::assertSame([], $parameters);

                return new Response('warning-only');
            }
        };

        $response = $this->invokeNaddr(
            ['kind' => KindsEnum::PUBLICATION_CONTENT->value, 'pubkey' => self::AUTHOR, 'identifier' => 'chapter-slug'],
            $entityManager,
            $controller,
        );

        self::assertSame('warning-only', $response->getContent());
    }

    public function testOtherLocalNaddrKindUsesSharedGenericPreview(): void
    {
        $localEvent = $this->event(30040, [['d', 'publication-index'], ['title', 'Generic local event']]);
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findByNaddr')
            ->with(30040, self::AUTHOR, 'publication-index')
            ->willReturn($localEvent);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Event::class)->willReturn($repository);
        $controller = new class extends DefaultController {
            public ?string $renderedView = null;
            /** @var array<string, mixed> */
            public array $renderParameters = [];

            protected function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                $this->renderedView = $view;
                $this->renderParameters = $parameters;

                return new Response('generic-event-preview');
            }
        };

        $response = $this->invokeNaddr(
            ['kind' => 30040, 'pubkey' => self::AUTHOR, 'identifier' => 'publication-index'],
            $entityManager,
            $controller,
        );

        self::assertSame('generic-event-preview', $response->getContent());
        self::assertSame('event_workspace/_api_event_preview.html.twig', $controller->renderedView);
        self::assertSame('Generic local event', $controller->renderParameters['presentation']->title);
    }

    public function testUnsupportedColdNaddrKindKeepsUnsupportedResponse(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('getRepository');

        $response = $this->invokeNaddr(
            ['kind' => 30078, 'pubkey' => self::AUTHOR, 'identifier' => 'private-settings'],
            $entityManager,
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Preview for kind 30078 not yet supported.', $response->getContent());
    }

    public function testExactEventPreviewUsesSharedPresentationAndKeepsOrderedOccurrences(): void
    {
        $event = new Event();
        $event->setId(self::EVENT_ID);
        $event->setKind(20);
        $event->setPubkey(self::AUTHOR);
        $event->setCreatedAt(123);
        $event->setTags([
            ['title', 'Unknown event title'],
            ['summary', 'Unknown event summary'],
            ['e', str_repeat('c', 64)],
            ['e', str_repeat('c', 64)],
        ]);
        $event->setContent('Unknown-kind body is intentionally opaque.');

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['id' => self::EVENT_ID])
            ->willReturn($event);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(Event::class)
            ->willReturn($repository);

        $controller = new class extends DefaultController {
            public ?string $renderedView = null;
            /** @var array<string, mixed> */
            public array $renderParameters = [];

            protected function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                $this->renderedView = $view;
                $this->renderParameters = $parameters;

                return new Response('shared event preview');
            }
        };
        $requestStack = new RequestStack();
        $requestStack->push(new Request(content: json_encode([
            'identifier' => 'nevent1example',
            'type' => 'nevent',
            'decoded' => ['id' => self::EVENT_ID],
        ], JSON_THROW_ON_ERROR)));

        $response = $controller->nostrPreview(
            $requestStack,
            $entityManager,
            $this->createMock(LoggerInterface::class),
            $this->presentations(),
            $this->accessPolicy(),
            $this->articlePreviews(),
            $this->eventVerifier(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('shared event preview', $response->getContent());
        self::assertSame('event_workspace/_api_event_preview.html.twig', $controller->renderedView);
        $presentation = $controller->renderParameters['presentation'];
        self::assertSame(self::EVENT_ID, $presentation->eventId);
        self::assertSame(20, $presentation->kind);
        self::assertSame('Unknown event title', $presentation->title);
        self::assertSame('Unknown event summary', $presentation->description);
        self::assertNull($presentation->textContent, 'Media body content must not bypass the generic opaque-body contract.');
        self::assertSame([2, 3], array_map(
            static fn ($reference): int => $reference->position,
            $presentation->references,
        ));
        self::assertSame(
            [str_repeat('c', 64), str_repeat('c', 64)],
            array_map(static fn ($reference): ?string => $reference->rawValue, $presentation->references),
        );
    }

    public function testMissingExactEventRetainsSuccessfulHtmlFragmentResponse(): void
    {
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['id' => self::EVENT_ID])
            ->willReturn(null);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(Event::class)
            ->willReturn($repository);

        $requestStack = new RequestStack();
        $requestStack->push(new Request(content: json_encode([
            'identifier' => 'note1example',
            'type' => 'note',
            'decoded' => ['id' => self::EVENT_ID],
        ], JSON_THROW_ON_ERROR)));

        $response = (new DefaultController())->nostrPreview(
            $requestStack,
            $entityManager,
            $this->createMock(LoggerInterface::class),
            $this->presentations(),
            $this->accessPolicy(),
            $this->articlePreviews(),
            $this->eventVerifier(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Event not found locally.', $response->getContent());
    }

    public function testPartialExactEventStillRendersSharedGenericIdentity(): void
    {
        $event = new Event();
        $event->setId(self::EVENT_ID);
        $event->setKind(1);
        $event->setPubkey(self::AUTHOR);

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['id' => self::EVENT_ID])
            ->willReturn($event);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('getRepository')
            ->with(Event::class)
            ->willReturn($repository);

        $controller = new class extends DefaultController {
            /** @var array<string, mixed> */
            public array $renderParameters = [];

            protected function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                $this->renderParameters = $parameters;

                return new Response('shared event preview');
            }
        };
        $requestStack = new RequestStack();
        $requestStack->push(new Request(content: json_encode([
            'identifier' => 'nevent1partial',
            'type' => 'nevent',
            'decoded' => ['id' => self::EVENT_ID],
        ], JSON_THROW_ON_ERROR)));

        $response = $controller->nostrPreview(
            $requestStack,
            $entityManager,
            $this->createMock(LoggerInterface::class),
            $this->presentations(),
            $this->accessPolicy(),
            $this->articlePreviews(),
            $this->eventVerifier(),
        );

        self::assertSame(200, $response->getStatusCode());
        $presentation = $controller->renderParameters['presentation'];
        self::assertSame(self::EVENT_ID, $presentation->eventId);
        self::assertSame(1, $presentation->kind);
        self::assertNull($presentation->title);
        self::assertSame((new NostrNip19Service())->encodeNpub(self::AUTHOR), $presentation->authorNpub);
        self::assertSame([], $presentation->references);
    }

    public function testDeniedExactEventUsesExistingMissingFragment(): void
    {
        $event = new Event();
        $event->setId(self::EVENT_ID);
        $event->setKind(1);
        $event->setPubkey(self::AUTHOR);

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['id' => self::EVENT_ID])
            ->willReturn($event);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(Event::class)->willReturn($repository);
        $requestStack = new RequestStack();
        $requestStack->push(new Request(content: json_encode([
            'identifier' => 'note1suppressed',
            'type' => 'note',
            'decoded' => ['id' => self::EVENT_ID],
        ], JSON_THROW_ON_ERROR)));

        $response = (new DefaultController())->nostrPreview(
            $requestStack,
            $entityManager,
            $this->createMock(LoggerInterface::class),
            $this->presentations(),
            $this->accessPolicy(false),
            $this->articlePreviews(),
            $this->eventVerifier(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Event not found locally.', $response->getContent());
    }

    private function presentations(): EventPresentationFactory
    {
        return new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service());
    }

    private function articlePreviews(): AuthoredArticlePreview
    {
        return new AuthoredArticlePreview($this->presentations());
    }

    private function eventVerifier(bool $valid = true): NostrEventVerifier
    {
        $signatureService = $this->createMock(SignatureServiceInterface::class);
        $signatureService->method('verify')->willReturn($valid);

        return new NostrEventVerifier(new NostrSigner($signatureService));
    }

    /** @param list<list<string>> $tags
     *  @return array<string, mixed>
     */
    private function signedArticleRaw(array $tags): array
    {
        $raw = NostrEvent::fromArray([
            'pubkey' => self::AUTHOR,
            'created_at' => 123,
            'kind' => KindsEnum::LONGFORM->value,
            'tags' => $tags,
            'content' => 'Article body',
        ])->toArray();
        $raw['sig'] = str_repeat('0', 128);

        return $raw;
    }

    /** @param array<mixed> $tags */
    private function areStringTags(array $tags): bool
    {
        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                return false;
            }
            foreach ($tag as $value) {
                if (!is_string($value)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function article(): Article
    {
        $raw = $this->signedArticleRaw([['d', 'article-slug'], ['title', 'Article title']]);
        $article = (new Article())
            ->setEventId($raw['id'])
            ->setPubkey(self::AUTHOR)
            ->setSlug('article-slug')
            ->setKind(KindsEnum::LONGFORM)
            ->setCreatedAt(123);
        $article->setRaw($raw);

        return $article;
    }

    /** @param list<list<string>> $tags */
    private function event(int $kind, array $tags): Event
    {
        $event = new Event();
        $event->setId(self::EVENT_ID);
        $event->setKind($kind);
        $event->setPubkey(self::AUTHOR);
        $event->setCreatedAt(123);
        $event->setTags($tags);

        return $event;
    }

    private function invokeNaddr(
        array $decoded,
        EntityManagerInterface $entityManager,
        ?DefaultController $controller = null,
        ?EventPreviewAccessPolicy $eventPreviewAccess = null,
        ?NostrEventVerifier $eventVerifier = null,
    ): Response {
        $controller ??= new DefaultController();
        $method = new \ReflectionMethod(DefaultController::class, 'handleNaddrPreview');

        return $method->invoke(
            $controller,
            $decoded,
            (string) \nostriphant\NIP19\Bech32::naddr(
                kind: (int) $decoded['kind'],
                pubkey: (string) $decoded['pubkey'],
                identifier: (string) $decoded['identifier'],
                relays: is_array($decoded['relays'] ?? null) ? $decoded['relays'] : [],
            ),
            $entityManager,
            $this->createMock(LoggerInterface::class),
            $this->presentations(),
            $eventPreviewAccess ?? $this->accessPolicy(),
            $this->articlePreviews(),
            $eventVerifier ?? $this->eventVerifier(),
        );
    }

    private function accessPolicy(bool $readable = true): EventPreviewAccessPolicy
    {
        return $this->accessPolicyForAuthor(self::AUTHOR, $readable);
    }

    private function accessPolicyForAuthor(string $author, bool $readable = true): EventPreviewAccessPolicy
    {
        $authors = $this->createMock(\App\Service\Reader\ContentAuthorAccessPolicy::class);
        $authors->expects(self::any())->method('assertReadable')->with($author);
        if (!$readable) {
            $authors->method('assertReadable')->willThrowException(new NotFoundHttpException());
        }

        $deletions = $this->createMock(\App\Repository\DeletedEventRepository::class);
        $deletions->method('isSuppressed')->willReturn(false);
        $hidden = $this->createMock(\App\Repository\HiddenCoordinateRepository::class);
        $hidden->method('isHidden')->willReturn(false);
        $security = $this->createMock(\Symfony\Bundle\SecurityBundle\Security::class);
        $security->method('getUser')->willReturn(null);

        return new EventPreviewAccessPolicy(
            $authors,
            $deletions,
            $hidden,
            new RecordIdentityService(),
            $security,
            $this->createMock(\App\Service\UserMuteListService::class),
            new \App\Service\Nostr\NostrKeyService(),
            new \Psr\Log\NullLogger(),
            new ArticleAccessService(new NostrIdentityService(
                $this->createMock(Nip19DecoderInterface::class),
                $this->createMock(Nip19EncoderInterface::class),
            )),
        );
    }
}
