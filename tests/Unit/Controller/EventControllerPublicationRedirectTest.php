<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\EventController;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\ArticleEventProjector;
use App\Service\Cache\RedisCacheService;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrLinkParser;
use App\Service\Nostr\UserRelayListService;
use App\Util\NostrKeyUtil;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event as NostrEvent;
use swentel\nostr\Nip19\Nip19Helper;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

class EventControllerPublicationRedirectTest extends TestCase
{
    public function testNaddrPublicationWithNestedIndexRedirectsToMagazinePage(): void
    {
        $pubkey = str_repeat('b', 64);
        $slug = 'daily-news';
        $event = $this->makePublicationEvent($pubkey, $slug, [
            ['d', $slug],
            ['a', '30040:' . str_repeat('c', 64) . ':world'],
        ]);

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findByNaddr')
            ->with(KindsEnum::PUBLICATION_INDEX->value, $pubkey, $slug)
            ->willReturn($event);

        $logger = $this->createMock(LoggerInterface::class);

        $controller = $this->makeController();
        $response = $controller->index(
            $this->encodeNaddr($event),
            new Request(),
            $this->createMock(RedisCacheService::class),
            new NostrLinkParser($logger),
            $logger,
            $repository,
            $this->createMock(MessageBusInterface::class),
            $this->createMock(NostrClient::class),
            $this->createMock(GenericEventProjector::class),
            $this->createMock(UserRelayListService::class),
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('/__redirect/magazine-index', $response->getTargetUrl());
        self::assertStringContainsString('mag=' . rawurlencode($slug), $response->getTargetUrl());
    }

    public function testNaddrPublicationWithNestedArticlesRedirectsToReadingListPage(): void
    {
        $pubkey = str_repeat('d', 64);
        $slug = 'my-list';
        $event = $this->makePublicationEvent($pubkey, $slug, [
            ['d', $slug],
            ['a', '30023:' . str_repeat('e', 64) . ':article-1'],
        ]);

        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findByNaddr')
            ->with(KindsEnum::PUBLICATION_INDEX->value, $pubkey, $slug)
            ->willReturn($event);

        $logger = $this->createMock(LoggerInterface::class);

        $controller = $this->makeController();
        $response = $controller->index(
            $this->encodeNaddr($event),
            new Request(),
            $this->createMock(RedisCacheService::class),
            new NostrLinkParser($logger),
            $logger,
            $repository,
            $this->createMock(MessageBusInterface::class),
            $this->createMock(NostrClient::class),
            $this->createMock(GenericEventProjector::class),
            $this->createMock(UserRelayListService::class),
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('/__redirect/reading-list', $response->getTargetUrl());
        self::assertStringContainsString('slug=' . rawurlencode($slug), $response->getTargetUrl());
        self::assertStringContainsString('npub=' . rawurlencode(NostrKeyUtil::hexToNpub($pubkey)), $response->getTargetUrl());
    }

    private function makeController(): EventController
    {
        $projector = $this->createMock(ArticleEventProjector::class);

        return new class($projector) extends EventController {
            public function redirectToRoute(string $route, array $parameters = [], int $status = 302): RedirectResponse
            {
                $query = http_build_query($parameters);
                $url = '/__redirect/' . $route . ($query !== '' ? ('?' . $query) : '');

                return new RedirectResponse($url, $status);
            }

            public function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                return $response ?? new Response('rendered:' . $view, Response::HTTP_OK);
            }
        };
    }

    private function makePublicationEvent(string $pubkey, string $slug, array $tags): Event
    {
        $event = new Event();
        $event->setId(str_repeat('a', 64));
        $event->setKind(KindsEnum::PUBLICATION_INDEX->value);
        $event->setPubkey($pubkey);
        $event->setContent('');
        $event->setCreatedAt(time());
        $event->setTags($tags);
        $event->setSig(str_repeat('f', 128));
        $event->setDTag($slug);

        return $event;
    }

    private function encodeNaddr(Event $event): string
    {
        $nip19 = new Nip19Helper();
        $nostr = new NostrEvent();
        $nostr->setId($event->getId());
        $nostr->setPublicKey($event->getPubkey());
        $nostr->setKind($event->getKind());

        return $nip19->encodeAddr($nostr, (string) $event->getDTag(), $event->getKind());
    }
}


