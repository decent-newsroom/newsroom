<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Bookshelf\FeaturedBookshelf;
use App\Controller\Bookshelf\LandingController;
use App\Entity\Event;
use App\Entity\User;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\ContentAuthorAccessPolicy;
use DecentNewsroom\BookshelfBundle\Contract\DirectoryEventStoreInterface;
use DecentNewsroom\BookshelfBundle\Service\Bookshelf\BookshelfDirectoryService;
use DecentNewsroom\BookshelfBundle\Service\Mercury\Exception\MercuryUnavailableException;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryApiClientInterface;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryBookService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Twig\Environment;

final class BookshelfLandingControllerTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $captured = null;
    private ?string $template = null;

    /** @dataProvider queries */
    public function testSearchBehaviorIsPreservedBelowThePromo(string $query, bool $searched, bool $tooShort, bool $fails): void
    {
        $api = $this->createMock(MercuryApiClientInterface::class);
        $search = $api->expects($searched && !$tooShort ? self::once() : self::never())->method('searchPublications');
        if ($searched && !$tooShort) {
            $search->with(trim($query));
            if ($fails) {
                $search->willThrowException(new MercuryUnavailableException('offline'));
            } else {
                $search->willReturn([]);
            }
        }
        $store = $this->createMock(DirectoryEventStoreInterface::class);
        $store->expects(self::never())->method('findAllByPubkeyAndKind');
        $response = $this->controller()->index(
            new Request(['q' => $query]), new MercuryBookService($api),
            new BookshelfDirectoryService($store), $this->featured(), new NullLogger(),
        );
        self::assertSame('@Bookshelf/pages/bookshelf.html.twig', $this->template);
        self::assertSame(trim($query), $this->captured['query']);
        self::assertSame($searched, $this->captured['searched']);
        self::assertSame($tooShort, $this->captured['queryTooShort']);
        self::assertSame(!$fails, $this->captured['available']);
        self::assertSame('unconfigured', $this->captured['featured']['state']);
        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public static function queries(): iterable
    {
        yield 'no search' => ['', false, false, false];
        yield 'whitespace' => ['   ', false, false, false];
        yield 'short query' => ['a', true, true, false];
        yield 'search' => ['  stories  ', true, false, false];
        yield 'api unavailable' => ['stories', true, false, true];
    }

    public function testAuthenticatedDirectoryContextIsPreserved(): void
    {
        $nip19 = new NostrNip19Service();
        $pubkey = str_repeat('a', 64);
        $user = new User();
        $user->setNpub($nip19->encodeNpub($pubkey));
        $source = new Event();
        $source->setId(str_repeat('b', 64));
        $source->setDTag(BookshelfDirectoryService::IDENTIFIER);
        $source->setTags([['d', BookshelfDirectoryService::IDENTIFIER], ['a', '30040:' . $pubkey . ':book']]);
        $store = $this->createMock(DirectoryEventStoreInterface::class);
        $store->expects(self::once())->method('findAllByPubkeyAndKind')->with($pubkey, 30045, 50)->willReturn([$source]);
        $this->controller($user)->index(
            new Request(), new MercuryBookService($this->createStub(MercuryApiClientInterface::class)),
            new BookshelfDirectoryService($store), $this->featured(), new NullLogger(),
        );
        self::assertSame(['30040:' . $pubkey . ':book'], $this->captured['directoryCoordinates']);
        self::assertSame($source->getTags(), $this->captured['directoryTags']);
    }

    /** @dataProvider invalidPages */
    public function testInvalidDirectoryPagingIsRejected(array $query): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->controller()->directory(new Request($query), $this->featured());
    }

    public static function invalidPages(): iterable
    {
        yield [['page' => '0']];
        yield [['page' => '2']];
        yield [['page' => ['1']]];
        yield [['page' => '99999999999999999999']];
        yield [['eventId' => 'bad']];
        yield [['eventId' => ['bad']]];
    }

    public function testUnconfiguredDirectoryHasNoPublicBrowsePage(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->controller()->directory(new Request(), $this->featured());
    }

    private function controller(?User $user = null): LandingController
    {
        $storage = new TokenStorage();
        if ($user !== null) {
            $storage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        }
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(function (string $template, array $parameters): string {
            $this->template = $template;
            $this->captured = $parameters;

            return 'landing';
        });
        $container = new Container();
        $container->set('security.token_storage', $storage);
        $container->set('twig', $twig);
        $controller = new LandingController();
        $controller->setContainer($container);

        return $controller;
    }

    private function featured(): FeaturedBookshelf
    {
        $api = $this->createMock(MercuryApiClientInterface::class);
        $api->expects(self::never())->method('getPublicationEventsByIds');
        $api->expects(self::never())->method('getPublicationsByAuthors');
        $nip19 = new NostrNip19Service();

        return new FeaturedBookshelf(
            '', $nip19, $this->createStub(EventRepository::class),
            new EventPresentationFactory(new RecordIdentityService(), $nip19), $api,
            $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(HiddenCoordinateRepository::class), new NullLogger(),
        );
    }
}
