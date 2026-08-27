<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Reader\ChapterController;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Message\FetchEventFromRelaysMessage;
use App\Repository\EventRepository;
use App\Service\Nostr\EventLookupKey;
use App\Util\CommonMark\Converter;
use nostriphant\NIP19\Bech32;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ChapterControllerTest extends TestCase
{
    public function testDbHitRendersStandaloneChapter(): void
    {
        $pubkey = str_repeat('a', 64);
        $chapter = $this->makeEvent(KindsEnum::PUBLICATION_CONTENT->value, $pubkey, 'intro', [
            ['d', 'intro'],
            ['title', 'Intro chapter'],
            ['summary', 'Summary'],
        ], '= Intro');
        $parent = $this->makeEvent(KindsEnum::PUBLICATION_INDEX->value, str_repeat('b', 64), 'weekly', [
            ['d', 'weekly'],
            ['title', 'Weekly publication'],
            ['a', KindsEnum::PUBLICATION_CONTENT->value . ':' . $pubkey . ':intro'],
        ]);

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findByNaddr')
            ->with(KindsEnum::PUBLICATION_CONTENT->value, $pubkey, 'intro')
            ->willReturn($chapter);
        $repository->expects(self::once())
            ->method('findReferencingEvents')
            ->with('a', KindsEnum::PUBLICATION_CONTENT->value . ':' . $pubkey . ':intro', [KindsEnum::PUBLICATION_INDEX->value], 1)
            ->willReturn([$parent]);

        $converter = $this->createMock(Converter::class);
        $converter->expects(self::once())
            ->method('convertAsciiDocToHTML')
            ->with('= Intro')
            ->willReturn('<h1>Intro</h1>');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $controller = $this->makeController();
        $response = $controller->show(
            $this->encodeNaddr(KindsEnum::PUBLICATION_CONTENT->value, $pubkey, 'intro'),
            $repository,
            $bus,
            $converter,
            $this->createMock(LoggerInterface::class),
        );

        self::assertSame('rendered:chapter/show.html.twig', $response->getContent());
        self::assertSame('<h1>Intro</h1>', $controller->renderedParameters['content']);
        self::assertSame('Intro chapter', $controller->renderedParameters['title']);
        self::assertSame(['title' => 'Weekly publication', 'slug' => 'weekly'], $controller->renderedParameters['parentPublication']);
    }

    public function testDbMissDispatchesAsyncFetchAndRendersLoadingPage(): void
    {
        $pubkey = str_repeat('a', 64);
        $naddr = $this->encodeNaddr(KindsEnum::PUBLICATION_CONTENT->value, $pubkey, 'missing');
        $lookupKey = EventLookupKey::forNaddr(KindsEnum::PUBLICATION_CONTENT->value, $pubkey, 'missing');

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findByNaddr')
            ->with(KindsEnum::PUBLICATION_CONTENT->value, $pubkey, 'missing')
            ->willReturn(null);
        $repository->expects(self::never())->method('findReferencingEvents');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(function (FetchEventFromRelaysMessage $message) use ($lookupKey, $pubkey): bool {
                return $message->lookupKey === $lookupKey
                    && $message->kind === KindsEnum::PUBLICATION_CONTENT->value
                    && $message->pubkey === $pubkey
                    && $message->identifier === 'missing'
                    && $message->type === 'naddr';
            }))
            ->willReturn(new Envelope(new \stdClass()));

        $converter = $this->createMock(Converter::class);
        $converter->expects(self::never())->method('convertAsciiDocToHTML');

        $controller = $this->makeController();
        $response = $controller->show(
            $naddr,
            $repository,
            $bus,
            $converter,
            $this->createMock(LoggerInterface::class),
        );

        self::assertSame('rendered:chapter/loading.html.twig', $response->getContent());
        self::assertSame($naddr, $controller->renderedParameters['naddr']);
        self::assertSame($lookupKey, $controller->renderedParameters['lookupKey']);
        self::assertSame(EventLookupKey::topic($lookupKey), $controller->renderedParameters['lookupTopic']);
    }

    public function testNonChapterNaddrRedirectsToGenericEventRoute(): void
    {
        $naddr = $this->encodeNaddr(KindsEnum::LONGFORM->value, str_repeat('c', 64), 'article');

        $controller = $this->makeController();
        $response = $controller->show(
            $naddr,
            $this->createMock(EventRepository::class),
            $this->createMock(MessageBusInterface::class),
            $this->createMock(Converter::class),
            $this->createMock(LoggerInterface::class),
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/__redirect/nevent?nevent=' . rawurlencode($naddr), $response->getTargetUrl());
    }

    private function makeController(): ChapterController
    {
        return new class extends ChapterController {
            public array $renderedParameters = [];

            public function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                $this->renderedParameters = $parameters;

                return $response ?? new Response('rendered:' . $view);
            }

            public function redirectToRoute(string $route, array $parameters = [], int $status = 302): RedirectResponse
            {
                $query = http_build_query($parameters);

                return new RedirectResponse('/__redirect/' . $route . ($query !== '' ? '?' . $query : ''), $status);
            }
        };
    }

    private function makeEvent(int $kind, string $pubkey, string $slug, array $tags, string $content = ''): Event
    {
        $event = new Event();
        $event->setId(str_repeat(substr($pubkey, 0, 1), 64));
        $event->setKind($kind);
        $event->setPubkey($pubkey);
        $event->setContent($content);
        $event->setCreatedAt(123);
        $event->setTags($tags);
        $event->setSig(str_repeat('f', 128));
        $event->setDTag($slug);

        return $event;
    }

    private function encodeNaddr(int $kind, string $pubkey, string $identifier): string
    {
        return (string) Bech32::naddr(
            kind: $kind,
            pubkey: $pubkey,
            identifier: $identifier,
        );
    }
}
