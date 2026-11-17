<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Reader\ArticleController;
use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Repository\HighlightRepository;
use App\Service\HighlightService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\Reader\HighlightSourceVisibility;
use App\Service\VanityNameService;
use App\Tests\Unit\Support\HighlightSourceVisibilityFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

final class ArticleHighlightVisibilityTest extends TestCase
{
    use HighlightSourceVisibilityFactory;

    private const AUTHOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @dataProvider viewers */
    public function testFrameFiltersThePayloadAndUsesFreshIdentitiesForGuests(bool $signedIn): void
    {
        $coordinate = '30023:' . self::AUTHOR . ':article';
        $items = [
            ['id' => self::id(1), 'article_ref' => $coordinate, 'content' => 'available'],
            ['id' => self::id(2), 'article_ref' => $coordinate, 'content' => 'missing exact source'],
        ];
        $highlights = $this->createMock(HighlightService::class);
        $highlights->expects($signedIn ? self::never() : self::once())
            ->method('getHighlightsForArticleFromDatabase')->with($coordinate)->willReturn($items);
        $highlights->expects($signedIn ? self::once() : self::never())
            ->method('getHighlightsForArticle')->with($coordinate)->willReturn($items);
        $events = $this->createMock(EventRepository::class);
        $events->expects($signedIn ? self::never() : self::once())->method('findByIds')->willReturn([
            self::id(1) => $this->event(self::id(1), 9802, [['a', $coordinate]]),
            self::id(2) => $this->event(self::id(2), 9802, [['a', $coordinate], ['e', self::id(99)]]),
        ]);
        $events->expects($signedIn ? self::never() : self::once())->method('findHighlightSourceTargets')
            ->willReturn([
                'eventIds' => [],
                'coordinates' => [$coordinate => $this->event(self::id(50), 30023, [['d', 'article']])],
            ]);
        $visibility = $this->visibility($events, $signedIn);
        $controller = $this->controller();
        $response = $controller->articleHighlightsFrame(
            'article', (new NostrNip19Service())->encodeNpub(self::AUTHOR), $highlights, $visibility,
        );

        self::assertSame($signedIn ? [self::id(1), self::id(2)] : [self::id(1)],
            array_column($controller->parameters['highlights'], 'id'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function testGuestDatabaseFailureIsNotSilentlyTurnedIntoAnEmptyFrame(): void
    {
        $highlights = $this->createMock(HighlightService::class);
        $highlights->method('getHighlightsForArticleFromDatabase')
            ->willThrowException(new \RuntimeException('database unavailable'));
        $visibility = $this->visibility($this->createMock(EventRepository::class), false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database unavailable');
        $this->controller()->articleHighlightsFrame(
            'article', (new NostrNip19Service())->encodeNpub(self::AUTHOR), $highlights, $visibility,
        );
    }

    public static function viewers(): iterable
    {
        yield 'guest' => [false];
        yield 'signed in' => [true];
    }

    private function controller(): ArticleController
    {
        return new class($this->createMock(VanityNameService::class), $this->createMock(ContentAuthorAccessPolicy::class)) extends ArticleController {
            public array $parameters = [];
            public function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                $this->parameters = $parameters;
                return $response ?? new Response();
            }
        };
    }

    private function visibility(EventRepository $events, bool $signedIn): HighlightSourceVisibility
    {
        $security = $this->createMock(Security::class);
        if ($signedIn) {
            $security->method('getUser')->willReturn($this->createMock(UserInterface::class));
        }
        $article = (new Article())->setEventId(self::id(50))->setKind(KindsEnum::LONGFORM)
            ->setPubkey(self::AUTHOR)->setSlug('article')->setCreatedAt(0);
        $article->setRaw(['tags' => [['d', 'article']]]);
        $articles = $this->createMock(ArticleRepository::class);
        $articles->method('findHighlightSourcesByEventIds')->willReturn([self::id(50) => [$article]]);

        return $this->makeHighlightVisibility($events, articles: $articles, security: $security);
    }

    private function event(string $id, int $kind, array $tags): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setPubkey(self::AUTHOR);
        $event->setKind($kind);
        $event->setTags($tags);
        return $event;
    }

    private static function id(int $number): string
    {
        return str_pad(dechex($number), 64, '0', STR_PAD_LEFT);
    }
}
