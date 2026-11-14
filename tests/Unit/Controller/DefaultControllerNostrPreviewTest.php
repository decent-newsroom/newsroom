<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\DefaultController;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        $response = $method->invoke(
            $controller,
            ['kind' => KindsEnum::WIKI->value, 'pubkey' => $pubkey, 'identifier' => $identifier],
            $entityManager,
            $this->createMock(LoggerInterface::class),
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertStringContainsString('Wiki Preview', $response->getContent());
        self::assertStringContainsString('/e/wiki-entry', $response->getContent());
    }

    public function testStoredNip54WikiPreviewRendersWikiCard(): void
    {
        $pubkey = str_repeat('b', 64);
        $identifier = 'wiki-entry';
        $wiki = new Event();
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
        $response = $method->invoke(
            $controller,
            ['kind' => KindsEnum::WIKI->value, 'pubkey' => $pubkey, 'identifier' => $identifier],
            $entityManager,
            $this->createMock(LoggerInterface::class),
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('rendered', $response->getContent());
        self::assertSame('components/Molecules/WikiPreview.html.twig', $controller->renderedView);
        self::assertSame($wiki, $controller->renderParameters['wiki']);
        self::assertSame('/e/wiki-entry', $controller->renderParameters['link']);
    }

    public function testExactEventPreviewUsesSharedPresentationAndKeepsOrderedOccurrences(): void
    {
        $event = new Event();
        $event->setId(self::EVENT_ID);
        $event->setKind(65001);
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
            $this->createMock(RedisCacheService::class),
            $this->presentations(),
            $this->accessPolicy(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('shared event preview', $response->getContent());
        self::assertSame('event_workspace/_api_event_preview.html.twig', $controller->renderedView);
        $presentation = $controller->renderParameters['presentation'];
        self::assertSame(self::EVENT_ID, $presentation->eventId);
        self::assertSame(65001, $presentation->kind);
        self::assertSame('Unknown event title', $presentation->title);
        self::assertSame('Unknown event summary', $presentation->description);
        self::assertNull($presentation->textContent, 'Unknown-kind body content must not bypass the generic opaque-body contract.');
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
            $this->createMock(RedisCacheService::class),
            $this->presentations(),
            $this->accessPolicy(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Event not found locally.', $response->getContent());
    }

    public function testPartialExactEventStillRendersSharedGenericIdentity(): void
    {
        $event = new Event();
        $event->setId(self::EVENT_ID);
        $event->setKind(65002);

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
            $this->createMock(RedisCacheService::class),
            $this->presentations(),
            $this->accessPolicy(),
        );

        self::assertSame(200, $response->getStatusCode());
        $presentation = $controller->renderParameters['presentation'];
        self::assertSame(self::EVENT_ID, $presentation->eventId);
        self::assertSame(65002, $presentation->kind);
        self::assertNull($presentation->title);
        self::assertNull($presentation->authorNpub);
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
            $this->createMock(RedisCacheService::class),
            $this->presentations(),
            $this->accessPolicy(false),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Event not found locally.', $response->getContent());
    }

    private function presentations(): EventPresentationFactory
    {
        return new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service());
    }

    private function accessPolicy(bool $readable = true): EventPreviewAccessPolicy
    {
        $authors = $this->createMock(\App\Service\Reader\ContentAuthorAccessPolicy::class);
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
        );
    }
}
