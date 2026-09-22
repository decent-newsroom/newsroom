<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\DefaultController;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class DefaultControllerNostrPreviewTest extends TestCase
{
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
}
