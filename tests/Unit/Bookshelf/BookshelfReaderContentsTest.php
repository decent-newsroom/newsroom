<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bookshelf;

use App\Bookshelf\BookshelfReaderContents;
use App\Bookshelf\BookshelfRelayBookLoader;
use App\Bookshelf\BookshelfReaderTraversalSigner;
use App\Controller\Bookshelf\BookshelfReadController;
use App\Entity\Event;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\Nostr\NostrClient;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use AsciiDocConverter;
use DecentNewsroom\BookshelfBundle\Contract\DirectoryEventStoreInterface;
use DecentNewsroom\BookshelfBundle\Service\Bookshelf\BookshelfDirectoryService;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryApiClientInterface;
use DecentNewsroom\BookshelfBundle\Service\Mercury\Model\MercuryEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class BookshelfReaderContentsTest extends TestCase
{
    /** @dataProvider rootReaderKinds */
    public function testReaderControllerDoesNotResolveOrConvertRootChildren(?int $kind, string $childIdentifier = 'child', string $rootIdentifier = 'book'): void
    {
        $pubkey = str_repeat('a', 64);
        $root = $this->entity($this->event('b', $pubkey, 30040, $rootIdentifier,
            $kind === null ? [['title', 'Metadata only']] : [['a', "$kind:$pubkey:$childIdentifier"]]));
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findByIds')->willReturn([$root->getId() => $root]);
        $events->expects(self::never())->method('findBookshelfReaderTargets');
        $api = $this->createMock(MercuryApiClientInterface::class);
        $api->expects(self::never())->method('getChaptersByAuthors');
        $api->expects(self::never())->method('getPublicationsByAuthors');
        $nostr = $this->createMock(NostrClient::class);
        $nostr->expects(self::never())->method('getEventsByCoordinates');
        $nostr->expects(self::never())->method('getEventsByIds');
        $logger = $this->createStub(LoggerInterface::class);
        $contents = new BookshelfReaderContents(
            $api, $nostr, $events, $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(DeletedEventRepository::class), $this->createStub(HiddenCoordinateRepository::class),
            $logger, $this->createStub(MercuryApiClientInterface::class),
        );
        $controller = new BookshelfReadController();
        $container = new Container();
        $container->set('security.token_storage', new TokenStorage());
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturnCallback(
            static function (string $template, array $parameters) use ($rootIdentifier, $pubkey): string {
                self::assertSame('bookshelf/read_nested.html.twig', $template);
                self::assertArrayHasKey('contents', $parameters);
                self::assertArrayNotHasKey('content', $parameters['contents']['entries'][0] ?? []);
                self::assertSame("30040:$pubkey:$rootIdentifier", $parameters['book']['coordinate']);
                return 'reader shell';
            },
        );
        $container->set('twig', $twig);
        $controller->setContainer($container);
        $converter = $this->createMock(AsciiDocConverter::class);
        $converter->expects(self::never())->method('convert');
        $api->expects(self::never())->method('getEvent');
        $response = $controller->read(
            $root->getId(), new Request(), new BookshelfRelayBookLoader($nostr, $logger),
            $contents, new BookshelfDirectoryService($this->createStub(DirectoryEventStoreInterface::class)), $logger,
            $this->createStub(UserMuteListService::class), new BookshelfReaderTraversalSigner('test-secret'),
        );
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public static function rootReaderKinds(): iterable
    {
        yield 'flat chapter' => [30041];
        yield 'nested index' => [30040];
        yield 'empty chapter identifier' => [30041, ''];
        yield 'empty root identifier and no children' => [null, '', ''];
        yield 'metadata-only index' => [null];
    }

    public function testTocRequestListsAllDirectReferencesWithoutFetchingOrConvertingChapters(): void
    {
        $pubkey = str_repeat('a', 64);
        $tags = [
            ['a', "30041:$pubkey:first"],
            ['a', "30040:$pubkey:nested"],
            ['a', "30041:$pubkey:first"],
            ['a', "30041:$pubkey:pinned", '', str_repeat('f', 64)],
            ['a', "30041:$pubkey:invalid", '', 'invalid-pin'],
        ];
        $root = $this->entity($this->event('b', $pubkey, 30040, 'book', $tags));
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findByIds')->with([$root->getId()])->willReturn([$root->getId() => $root]);
        $events->expects(self::never())->method('findBookshelfReaderTargets');
        $api = $this->createMock(MercuryApiClientInterface::class);
        $api->expects(self::never())->method('getChaptersByAuthors');
        $api->expects(self::never())->method('getPublicationsByAuthors');
        $api->expects(self::never())->method('getEventsByIds');
        $nostr = $this->createMock(NostrClient::class);
        $nostr->expects(self::never())->method('getEventsByCoordinates');
        $nostr->expects(self::never())->method('getEventsByIds');
        $reader = new BookshelfReaderContents(
            $api, $nostr, $events, $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(DeletedEventRepository::class), $this->createStub(HiddenCoordinateRepository::class),
            $this->createStub(LoggerInterface::class), $api,
        );
        $signer = new BookshelfReaderTraversalSigner('test-secret');
        $container = new Container();
        $container->set('security.token_storage', new TokenStorage());
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturnCallback(
            static function (string $template, array $parameters) use ($signer, $root): string {
                self::assertSame('bookshelf/_reader_contents.html.twig', $template);
                self::assertSame(['first', 'nested', 'first', 'pinned', 'invalid'], array_column($parameters['contents']['entries'], 'title'));
                self::assertSame([1, 2, 3, 4, 5], array_column($parameters['contents']['entries'], 'position'));
                self::assertSame([1, 3, 4], array_keys($parameters['chapterSignatures']));
                self::assertTrue($parameters['contents']['entries'][4]['invalid']);
                self::assertNotSame($parameters['chapterSignatures'][1], $parameters['chapterSignatures'][3]);
                self::assertTrue($signer->isValidChapter($root->getId(), [], [], 4, $parameters['chapterSignatures'][4]));
                foreach ($parameters['contents']['entries'] as $entry) {
                    self::assertArrayNotHasKey('content', $entry);
                    self::assertArrayNotHasKey('html', $entry);
                }
                return 'outline only';
            },
        );
        $container->set('twig', $twig);
        $controller = new BookshelfReadController();
        $controller->setContainer($container);
        $response = $controller->contents($root->getId(), new Request(), $reader, $this->createStub(UserMuteListService::class), $signer);
        self::assertSame('outline only', $response->getContent());
    }

    public function testChapterRequestResolvesAndConvertsOnlyItsOwnOccurrence(): void
    {
        $pubkey = str_repeat('a', 64);
        $tags = [];
        for ($i = 1; $i <= 6; ++$i) {
            $tags[] = ['a', "30041:$pubkey:chapter-$i"];
        }
        $root = $this->entity($this->event('b', $pubkey, 30040, 'book', $tags));
        $chapter = $this->entity($this->event('c', $pubkey, 30041, 'chapter-6', [['title', 'Sixth']], 'Body six'));
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findByIds')->willReturn([$root->getId() => $root]);
        $events->expects(self::once())->method('findBookshelfReaderTargets')
            ->with(["30041:$pubkey:chapter-6"], [])
            ->willReturn(['coordinates' => ["30041:$pubkey:chapter-6" => $chapter], 'eventIds' => []]);
        $api = $this->createMock(MercuryApiClientInterface::class);
        $api->expects(self::never())->method('getChaptersByAuthors');
        $nostr = $this->createMock(NostrClient::class);
        $nostr->expects(self::never())->method('getEventsByCoordinates');
        $logger = $this->createStub(LoggerInterface::class);
        $reader = new BookshelfReaderContents(
            $api, $nostr, $events, $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(DeletedEventRepository::class), $this->createStub(HiddenCoordinateRepository::class),
            $logger, $this->createStub(MercuryApiClientInterface::class),
        );
        $controller = new BookshelfReadController();
        $container = new Container();
        $container->set('security.token_storage', new TokenStorage());
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturnCallback(
            static function (string $template, array $parameters): string {
                self::assertSame('bookshelf/_reader_chapter.html.twig', $template);
                self::assertSame([], $parameters['path']);
                self::assertSame(6, $parameters['position']);
                self::assertSame('Sixth', $parameters['chapter']['title']);
                self::assertSame('<p>Six</p>', $parameters['chapter']['html']);
                return 'chapter six';
            },
        );
        $container->set('twig', $twig);
        $controller->setContainer($container);
        $converter = $this->createMock(AsciiDocConverter::class);
        $converter->expects(self::once())->method('convert')->with('Body six')->willReturn('<p>Six</p>');
        $signer = new BookshelfReaderTraversalSigner('test-secret');
        $response = $controller->chapter(
            $root->getId(), 6, new Request(['signature' => $signer->signChapter($root->getId(), [], [], 6)]),
            $reader, $this->createStub(UserMuteListService::class), $signer, $converter, $logger,
        );
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testChapterSignatureCannotBeReusedForAnotherOccurrence(): void
    {
        $id = str_repeat('b', 64);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findByIds');
        $events->expects(self::never())->method('findBookshelfReaderTargets');
        $reader = new BookshelfReaderContents(
            $this->createStub(MercuryApiClientInterface::class), $this->createStub(NostrClient::class), $events,
            $this->createStub(ContentAuthorAccessPolicy::class), $this->createStub(DeletedEventRepository::class),
            $this->createStub(HiddenCoordinateRepository::class), $this->createStub(LoggerInterface::class),
            $this->createStub(MercuryApiClientInterface::class),
        );
        $signer = new BookshelfReaderTraversalSigner('test-secret');
        $converter = $this->createMock(AsciiDocConverter::class);
        $converter->expects(self::never())->method('convert');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        (new BookshelfReadController())->chapter(
            $id, 2, new Request(['signature' => $signer->signChapter($id, [], [], 1)]),
            $reader, $this->createStub(UserMuteListService::class), $signer, $converter, $this->createStub(LoggerInterface::class),
        );
    }

    public function testChapterRequestReauthorizesRootBeforeResolvingAnyTarget(): void
    {
        $pubkey = str_repeat('a', 64);
        $root = $this->entity($this->event('b', $pubkey, 30040, 'book', [['a', "30041:$pubkey:chapter"]]));
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findByIds')->willReturn([$root->getId() => $root]);
        $events->expects(self::never())->method('findBookshelfReaderTargets');
        $authors = $this->createStub(ContentAuthorAccessPolicy::class);
        $authors->method('suppressedPubkeys')->willReturn([$pubkey]);
        $reader = new BookshelfReaderContents(
            $this->createStub(MercuryApiClientInterface::class), $this->createStub(NostrClient::class), $events,
            $authors, $this->createStub(DeletedEventRepository::class), $this->createStub(HiddenCoordinateRepository::class),
            $this->createStub(LoggerInterface::class), $this->createStub(MercuryApiClientInterface::class),
        );
        $container = new Container();
        $container->set('security.token_storage', new TokenStorage());
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturnCallback(static function (string $template, array $parameters): string {
            self::assertNull($parameters['chapter']);
            return 'unavailable';
        });
        $container->set('twig', $twig);
        $controller = new BookshelfReadController();
        $controller->setContainer($container);
        $signer = new BookshelfReaderTraversalSigner('test-secret');
        $converter = $this->createMock(AsciiDocConverter::class);
        $converter->expects(self::never())->method('convert');
        $controller->chapter(
            $root->getId(), 1, new Request(['signature' => $signer->signChapter($root->getId(), [], [], 1)]),
            $reader, $this->createStub(UserMuteListService::class), $signer, $converter, $this->createStub(LoggerInterface::class),
        );
    }

    public function testChapterFragmentsMatchTheAutomaticFramesIncludingNestedIndexBodies(): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'), ['strict_variables' => true]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $rootId = str_repeat('b', 64);
        $html = $twig->render('bookshelf/_reader_chapter.html.twig', [
            'rootId' => $rootId, 'path' => [], 'position' => 6,
            'chapter' => ['title' => 'Chapter six', 'summary' => 'Summary', 'html' => '<p>Body</p>'],
        ]);
        $crawler = new Crawler($html);
        self::assertCount(1, $crawler->filter('turbo-frame#bookshelf-chapter-' . $rootId . '-root-6'));
        self::assertSame('Chapter six', $crawler->filter('h2')->text());
        self::assertSame('Body', $crawler->filter('.bookshelf-chapter__content')->text());

        $html = $twig->render('bookshelf/_reader_chapter.html.twig', [
            'rootId' => $rootId, 'path' => [3, 5], 'position' => -1, 'chapter' => ['html' => '<p>Introduction</p>'],
        ]);
        $crawler = new Crawler($html);
        self::assertCount(1, $crawler->filter('turbo-frame#bookshelf-chapter-' . $rootId . '-3-5--1'));
        self::assertSame('Introduction', $crawler->filter('.bookshelf-chapter__content')->text());
        self::assertCount(0, $crawler->filter('h2'));
    }

    public function testUnopenedOrUnavailableRootNeverTriggersLegacyChapterLoading(): void
    {
        $id = str_repeat('b', 64);
        $events = $this->createStub(EventRepository::class);
        $events->method('findByIds')->willReturn([]);
        $events->method('findBookshelfReaderTargets')->willReturn(['coordinates' => [], 'eventIds' => []]);
        $api = $this->createMock(MercuryApiClientInterface::class);
        $api->expects(self::once())->method('getEvent')->with($id)->willReturn(null);
        $api->expects(self::never())->method('getEventsByIds');
        $api->expects(self::never())->method('getChaptersByAuthors');
        $nostr = $this->createMock(NostrClient::class);
        $nostr->expects(self::once())->method('getEventsByIds')->with([$id])->willReturn([]);
        $nostr->expects(self::never())->method('getEventsByCoordinates');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with('Bookshelf index unavailable; reader can be retried.', ['event_id' => $id]);
        $reader = new BookshelfReaderContents(
            $api, $nostr, $events, $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(DeletedEventRepository::class), $this->createStub(HiddenCoordinateRepository::class),
            $logger, $this->createStub(MercuryApiClientInterface::class),
        );
        $container = new Container();
        $container->set('security.token_storage', new TokenStorage());
        $twig = $this->createMock(Environment::class);
        $twig->expects(self::once())->method('render')->willReturnCallback(static function (string $template, array $parameters): string {
            self::assertSame('bookshelf/read_unavailable.html.twig', $template);
            self::assertFalse($parameters['available']);
            return 'unavailable';
        });
        $container->set('twig', $twig);
        $controller = new BookshelfReadController();
        $controller->setContainer($container);
        $response = $controller->read(
            $id, new Request(), new BookshelfRelayBookLoader($nostr, $logger), $reader,
            new BookshelfDirectoryService($this->createStub(DirectoryEventStoreInterface::class)),
            $logger, $this->createStub(UserMuteListService::class), new BookshelfReaderTraversalSigner('test-secret'),
        );
        self::assertSame(503, $response->getStatusCode());
    }

    public function testSeparateChapterRequestBindsOpenedIndexRevisionAndRejectsTampering(): void
    {
        $pubkey = str_repeat('a', 64);
        $root = $this->entity($this->event('b', $pubkey, 30040, 'book', [['a', "30040:$pubkey:part"]]));
        $tags = array_fill(0, 6, ['a', "30041:$pubkey:section"]);
        $old = $this->entity($this->event('c', $pubkey, 30040, 'part', $tags, 'Index introduction'));
        $new = $this->entity($this->event('d', $pubkey, 30040, 'part', [['a', "30040:$pubkey:new"]]));
        $chapter = $this->entity($this->event('e', $pubkey, 30041, 'section', [], 'Section body'));
        $events = $this->createMock(EventRepository::class);
        $events->method('findByIds')->willReturn([$root->getId() => $root]);
        $events->expects(self::exactly(3))->method('findBookshelfReaderTargets')->willReturnCallback(
            static function (array $coordinates, array $ids) use ($old, $new, $chapter, $pubkey): array {
                if ($ids === []) {
                    return ['coordinates' => [$coordinates[0] => $coordinates[0] === "30040:$pubkey:part" ? $old : $chapter], 'eventIds' => []];
                }
                self::assertSame([$old->getId()], $ids);
                return ['coordinates' => [], 'eventIds' => [$old->getId() => $old, $new->getId() => $new]];
            },
        );
        $logger = $this->createStub(LoggerInterface::class);
        $reader = new BookshelfReaderContents(
            $this->createStub(MercuryApiClientInterface::class), $this->createStub(NostrClient::class), $events,
            $this->createStub(ContentAuthorAccessPolicy::class), $this->createStub(DeletedEventRepository::class),
            $this->createStub(HiddenCoordinateRepository::class), $logger, $this->createStub(MercuryApiClientInterface::class),
        );
        $captured = [];
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(static function (string $template, array $parameters) use (&$captured): string {
            $captured = $parameters;
            return 'fragment';
        });
        $container = new Container();
        $container->set('security.token_storage', new TokenStorage());
        $container->set('twig', $twig);
        $controller = new BookshelfReadController();
        $controller->setContainer($container);
        $signer = new BookshelfReaderTraversalSigner('test-secret');
        $converter = $this->createMock(AsciiDocConverter::class);
        $converter->expects(self::once())->method('convert')->with('Section body')->willReturn('<p>Section</p>');
        $mutes = $this->createStub(UserMuteListService::class);
        $controller->contents($root->getId(), new Request([
            'path' => '1', 'signature' => $signer->sign($root->getId(), [1], []),
        ]), $reader, $mutes, $signer);
        self::assertSame([$old->getId()], $captured['revisions']);
        self::assertCount(6, $captured['contents']['entries']);
        self::assertTrue($signer->isValidChapter($root->getId(), [1], [$old->getId()], -1, $captured['bodySignature']));
        $signature = $captured['chapterSignatures'][6];
        $controller->chapter($root->getId(), 6, new Request([
            'path' => '1', 'revisions' => $old->getId(), 'signature' => $signature,
        ]), $reader, $mutes, $signer, $converter, $logger);
        self::assertSame($chapter->getId(), $captured['chapter']['id']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $controller->chapter($root->getId(), 6, new Request([
            'path' => '1', 'revisions' => $new->getId(), 'signature' => $signature,
        ]), $reader, $mutes, $signer, $converter, $logger);
    }

    public function testRemotePageQueriesOnlyExactNonblankCoordinates(): void
    {
        $authors = array_map(static fn (int $i): string => str_repeat((string) $i, 64), range(1, 5));
        $tags = [];
        foreach ($authors as $i => $author) {
            $tags[] = ['a', '30041:' . $author . ':' . ($i === 4 ? '' : 'chapter-' . $i)];
        }
        $root = (object) $this->event('b', str_repeat('a', 64), 30040, 'book', $tags)->toArray();
        $api = $this->createMock(MercuryApiClientInterface::class);
        $calls = [];
        $api->expects(self::exactly(4))->method('getChaptersByAuthors')->willReturnCallback(
            static function (array $pubkeys, int $limit, array $identifiers) use (&$calls): array {
                $calls[] = [$pubkeys, $limit, $identifiers];
                return [];
            },
        );
        $nostr = $this->createMock(NostrClient::class);
        $nostr->expects(self::once())->method('getEventsByCoordinates')->willReturnCallback(
            static function (array $coordinates) use ($authors): array {
                self::assertContains('30041:' . $authors[4] . ':', $coordinates);
                return [];
            },
        );
        $page = $this->service($api, $nostr)->childrenPage($root, [], 1);
        self::assertCount(5, $page['entries']);
        foreach ($calls as $i => [$pubkeys, $limit, $identifiers]) {
            self::assertSame([$authors[$i]], $pubkeys);
            self::assertSame(1, $limit);
            self::assertSame(['chapter-' . $i], $identifiers);
        }
    }

    public function testBoundNestedAncestryRechecksPinsCoordinatesAndVisibility(): void
    {
        $pubkey = str_repeat('a', 64);
        $root = $this->event('b', $pubkey, 30040, 'book', [['a', "30040:$pubkey:part", '', str_repeat('c', 64)]]);
        $part = $this->event('c', $pubkey, 30040, 'part', [['a', "30040:$pubkey:leaf"]]);
        $leaf = $this->event('d', $pubkey, 30040, 'leaf');
        $api = $this->createStub(MercuryApiClientInterface::class);
        $api->method('getPublicationEventsByIds')->willReturnCallback(static fn (array $ids): array =>
            in_array(str_repeat('c', 64), $ids, true) ? [$part] : [$leaf]);
        $reader = $this->service($api, $this->createStub(NostrClient::class));
        $resolved = [];
        self::assertNotNull($reader->indexAtPath((object) $root->toArray(), [1, 1], [], [
            str_repeat('c', 64), str_repeat('d', 64),
        ], $resolved));
        self::assertSame([str_repeat('c', 64), str_repeat('d', 64)], $resolved);
        self::assertNull($reader->indexAtPath((object) $root->toArray(), [1], [], [str_repeat('d', 64)]));
        self::assertNull($reader->indexAtPath((object) $root->toArray(), [1, 1], [$pubkey], [
            str_repeat('c', 64), str_repeat('d', 64),
        ]));
    }

    public function testReaderSelectsNewestThenLowestIdAmongReturnedRevisions(): void
    {
        $pubkey = str_repeat('a', 64);
        $root = (object) $this->event('b', $pubkey, 30040, 'book', [['a', "30041:$pubkey:section"]])->toArray();
        $laterId = $this->event('e', $pubkey, 30041, 'section', [], 'later ID');
        $lowerId = $this->event('c', $pubkey, 30041, 'section', [], 'lower ID');
        $api = $this->createStub(MercuryApiClientInterface::class);
        $api->method('getChaptersByAuthors')->willReturn([$laterId, $lowerId]);
        $entries = $this->service($api, $this->createStub(NostrClient::class))->childrenPage($root, [], 1)['entries'];
        self::assertSame(str_repeat('c', 64), $entries[0]['id']);
        self::assertSame('lower ID', $entries[0]['content']);
    }

    /** @dataProvider coordinateKinds */
    public function testOrderedBooksApiIsPreferredToAmbiguouslyOrderedMercury(int $kind): void
    {
        $pubkey = str_repeat('a', 64);
        $root = (object) $this->event('b', $pubkey, 30040, 'book', [['a', "$kind:$pubkey:section"]])->toArray();
        $winner = $this->event('c', $pubkey, $kind, 'section', [], 'Books winner');
        $higherId = $this->event('e', $pubkey, $kind, 'section', [], 'Mercury tie');
        $books = $this->createMock(MercuryApiClientInterface::class);
        $method = $kind === 30040 ? 'getPublicationsByAuthors' : 'getChaptersByAuthors';
        $books->expects(self::once())->method($method)
            ->with([$pubkey], 1, ['section'])->willReturn([$winner]);
        $mercury = $this->createMock(MercuryApiClientInterface::class);
        $mercury->expects(self::never())->method($method)->willReturn([$higherId]);
        $nostr = $this->createMock(NostrClient::class);
        $nostr->expects(self::never())->method('getEventsByCoordinates');
        $reader = new BookshelfReaderContents(
            $mercury, $nostr, $this->events(), $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(DeletedEventRepository::class), $this->createStub(HiddenCoordinateRepository::class),
            $this->createStub(LoggerInterface::class), $books,
        );
        if ($kind === 30040) {
            self::assertSame($winner->id, $reader->indexAtPath($root, [1], [])->id);
        } else {
            self::assertSame($winner->id, $reader->childrenPage($root, [], 1)['entries'][0]['id']);
        }
    }

    /** @dataProvider coordinateKinds */
    public function testUnavailableBooksApiDoesNotUseUnorderedMercuryCoordinate(int $kind): void
    {
        $pubkey = str_repeat('a', 64);
        $coordinate = "$kind:$pubkey:section";
        $root = (object) $this->event('b', $pubkey, 30040, 'book', [['a', $coordinate]])->toArray();
        $low = $this->event('c', $pubkey, $kind, 'section', [], 'Relay winner');
        $high = $this->event('e', $pubkey, $kind, 'section', [], 'Mercury tie');
        $books = $this->createMock(MercuryApiClientInterface::class);
        $method = $kind === 30040 ? 'getPublicationsByAuthors' : 'getChaptersByAuthors';
        $books->expects(self::once())->method($method)->willReturn([]);
        $mercury = $this->createMock(MercuryApiClientInterface::class);
        $mercury->expects(self::never())->method($method)->willReturn([$high]);
        $nostr = $this->createMock(NostrClient::class);
        $nostr->expects(self::once())->method('getEventsByCoordinates')->with([$coordinate], [])
            ->willReturn([$coordinate => (object) $low->toArray()]);
        $reader = new BookshelfReaderContents(
            $mercury, $nostr, $this->events(), $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(DeletedEventRepository::class), $this->createStub(HiddenCoordinateRepository::class),
            $this->createStub(LoggerInterface::class), $books,
        );
        if ($kind === 30040) {
            self::assertSame($low->id, $reader->indexAtPath($root, [1], [])->id);
        } else {
            self::assertSame($low->id, $reader->childrenPage($root, [], 1)['entries'][0]['id']);
        }
    }

    public static function coordinateKinds(): iterable
    {
        yield 'nested index' => [30040];
        yield 'chapter' => [30041];
    }

    public function testUnavailableReaderHasDirectRetryLink(): void
    {
        $files = new FilesystemLoader(dirname(__DIR__, 3) . '/templates');
        $files->addPath(dirname(__DIR__, 3) . '/vendor/decent-newsroom/bookshelf-bundle/src/Resources/views', 'Bookshelf');
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['@Bookshelf/bookshelf-layout.html.twig' => '{% block title %}{% endblock %}{% block ogtags %}{% endblock %}{% block body %}{% endblock %}{% block aside %}{% endblock %}']),
            $files,
        ]), ['strict_variables' => true]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $twig->addFunction(new TwigFunction('path', static fn (string $route, array $params = []): string =>
            $route === 'bookshelf_read_native_fallback' ? '/bookshelf/' . $params['id'] : '/bookshelf'));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (): string => 'csrf'));
        $twig->addFunction(new TwigFunction('ux_icon', static fn (): string => ''));
        $id = str_repeat('b', 64);
        $html = $twig->render('bookshelf/read_unavailable.html.twig', [
            'book' => null, 'available' => false, 'query' => '', 'readerId' => $id,
            'app' => ['user' => null],
        ]);
        self::assertSame('/bookshelf/' . $id, (new Crawler($html))->filter('.bookshelf-unavailable a')->first()->attr('href'));
    }

    public function testLazyPagesResolveOnlySectionsAndTheSelectedIndex(): void
    {
        $pubkey = str_repeat('a', 64);
        $root = $this->event('b', $pubkey, 30040, 'book', [
            ['a', "30041:$pubkey:first"], ['a', "30040:$pubkey:part"],
            ['a', "30040:$pubkey:part"], ['a', "30040:$pubkey:other"],
        ]);
        $part = $this->event('c', $pubkey, 30040, 'part', [
            ['a', "30041:$pubkey:second"], ['a', "30040:$pubkey:book"],
        ]);
        $first = $this->event('d', $pubkey, 30041, 'first', [], 'First');
        $second = $this->event('e', $pubkey, 30041, 'second', [], 'Second');
        $api = $this->createMock(MercuryApiClientInterface::class);
        $indexFetches = 0;
        $api->expects(self::exactly(2))->method('getPublicationsByAuthors')
            ->with([$pubkey], 1, ['part'])->willReturnCallback(
                static function () use (&$indexFetches, $part): array {
                    ++$indexFetches;
                    return [$part];
                },
            );
        $api->expects(self::exactly(2))->method('getChaptersByAuthors')
            ->willReturnCallback(static fn (array $authors, int $limit, array $identifiers): array =>
                $identifiers === ['first'] ? [$first] : [$second]);
        $service = $this->service($api, $this->createStub(NostrClient::class));
        $source = (object) $root->toArray();
        $page = $service->childrenPage($source, [], 1);
        self::assertSame(0, $indexFetches);
        self::assertSame(['first', 'part', 'part', 'other'], array_column($page['entries'], 'title'));
        self::assertSame([1, 2, 3, 4], array_column($page['entries'], 'position'));
        self::assertSame('First', $page['entries'][0]['content']);
        $index = $service->indexAtPath($source, [3], []);
        self::assertNotNull($index);
        self::assertSame(1, $indexFetches);
        $nested = $service->childrenPage($index, [], 1);
        self::assertSame('Second', $nested['entries'][0]['content']);
        self::assertNull($service->indexAtPath($source, [3, 2], []));
    }

    public function testLazyPathEnforcesPinsScopeAndDepth(): void
    {
        $pubkey = str_repeat('a', 64);
        $root = $this->event('b', $pubkey, 30040, 'book', [
            ['a', "30040:$pubkey:part", '', str_repeat('f', 64)],
            ['a', "30040:$pubkey:private"],
        ]);
        $wrong = $this->event('f', $pubkey, 30040, 'other');
        $private = $this->event('c', $pubkey, 30040, 'private', [['s', 'private']]);
        $api = $this->createStub(MercuryApiClientInterface::class);
        $api->method('getPublicationEventsByIds')->willReturn([$wrong]);
        $api->method('getPublicationsByAuthors')->willReturn([$private]);
        $service = $this->service($api, $this->createStub(NostrClient::class));
        $source = (object) $root->toArray();
        self::assertNull($service->indexAtPath($source, [1], []));
        self::assertNull($service->indexAtPath($source, [2], []));
        self::assertNull($service->indexAtPath($source, [], [$pubkey]));
        self::assertNull($service->indexAtPath($source, [0, 0, 0, 0, 0, 0], []));
        self::assertNull($service->indexAtPath($source, [500], []));
    }

    public function testLazyPathRechecksEveryAncestorAndExactVersion(): void
    {
        $pubkey = str_repeat('a', 64);
        $other = str_repeat('f', 64);
        $root = $this->event('b', $pubkey, 30040, 'book', [
            ['a', "30040:$other:part", '', str_repeat('c', 64)],
        ]);
        $part = $this->event('c', $other, 30040, 'part', [['a', "30040:$pubkey:leaf"]]);
        $leaf = $this->event('d', $pubkey, 30040, 'leaf', [], 'Leaf body');
        $api = $this->createStub(MercuryApiClientInterface::class);
        $api->method('getPublicationEventsByIds')->willReturn([$part]);
        $api->method('getPublicationsByAuthors')->willReturn([$leaf]);
        $hidden = $this->createStub(HiddenCoordinateRepository::class);
        $hidden->method('findHiddenCoordinates')->willReturnCallback(static fn (array $coordinates): array =>
            in_array("30040:$other:part", $coordinates, true) ? ["30040:$other:part"] : []);
        $service = new BookshelfReaderContents(
            $api, $this->createStub(NostrClient::class), $this->events(), $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(DeletedEventRepository::class), $hidden, $this->createStub(LoggerInterface::class),
            $this->createStub(MercuryApiClientInterface::class),
        );
        self::assertNull($service->indexAtPath((object) $root->toArray(), [1, 1], []));

        $readable = $this->service($api, $this->createStub(NostrClient::class));
        $result = $readable->indexAtPath((object) $root->toArray(), [1, 1], []);
        self::assertNotNull($result);
        self::assertSame('Leaf body', $result->content);
        self::assertNull($readable->indexAtPath((object) $root->toArray(), [1, 1], [$other]));
    }

    public function testLazyPageBoundsAndCollapsedMarkup(): void
    {
        $pubkey = str_repeat('a', 64);
        $tags = array_fill(0, 501, ['a', "30040:$pubkey:part"]);
        $source = (object) $this->event('b', $pubkey, 30040, 'book', $tags)->toArray();
        $api = $this->createMock(MercuryApiClientInterface::class);
        $api->expects(self::never())->method('getPublicationsByAuthors');
        $service = $this->service($api, $this->createStub(NostrClient::class));
        $page = $service->outline($source);
        self::assertCount(500, $page['entries']);
        self::assertFalse($page['hasNext']);
        self::assertTrue($page['truncated']);

        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'), ['strict_variables' => true]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $twig->addFunction(new TwigFunction('path', static fn (): string => '/contents'));
        $html = $twig->render('bookshelf/_reader_contents.html.twig', [
            'rootId' => str_repeat('b', 64), 'path' => [], 'page' => 1,
            'contents' => $page, 'indexBody' => null, 'indexMetadata' => null,
            'revisions' => [], 'childSignatures' => array_fill_keys(range(1, 500), 'signed'),
            'bodySignature' => null,
        ]);
        $crawler = new Crawler($html);
        self::assertCount(500, $crawler->filter('details.bookshelf-reader__index:not([open])'));
        self::assertCount(500, $crawler->filter('details.bookshelf-reader__index turbo-frame[loading="lazy"]'));
        self::assertCount(500, $crawler->filter('.bookshelf-toc li'));
    }

    public function testNestedReaderAndChapterLinksCarryBoundRevisionsAndIndependentSignatures(): void
    {
        $id = str_repeat('b', 64);
        $revision = str_repeat('c', 64);
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'), ['strict_variables' => true]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $twig->addFunction(new TwigFunction('path', static fn (string $route, array $params): string =>
            '/contents?' . http_build_query($params)));
        $html = $twig->render('bookshelf/_reader_contents.html.twig', [
            'rootId' => $id, 'path' => [3], 'page' => 1,
            'contents' => ['entries' => [
                ['kind' => 30040, 'position' => 7, 'title' => 'Part', 'invalid' => false],
                ['kind' => 30041, 'position' => 8, 'title' => 'Chapter', 'invalid' => false],
            ], 'hasNext' => true, 'truncated' => false],
            'indexBody' => null, 'indexMetadata' => null,
            'revisions' => [$revision], 'childSignatures' => [7 => 'child-signature'], 'pageSignature' => 'page-signature',
            'chapterSignatures' => [8 => 'chapter-signature'],
            'bodySignature' => null,
        ]);
        $crawler = new Crawler($html);
        $child = $crawler->filter('details.bookshelf-reader__index turbo-frame');
        parse_str((string) parse_url($child->attr('src'), PHP_URL_QUERY), $childQuery);
        self::assertSame(['id' => $id, 'path' => '3.7', 'revisions' => $revision, 'signature' => 'child-signature'], $childQuery);
        self::assertSame($child->attr('src'), $child->filter('a')->attr('href'));
        $chapter = $crawler->filter('turbo-frame#bookshelf-chapter-' . $id . '-3-8');
        parse_str((string) parse_url($chapter->attr('src'), PHP_URL_QUERY), $chapterQuery);
        self::assertSame(['id' => $id, 'position' => '8', 'path' => '3', 'revisions' => $revision, 'signature' => 'chapter-signature'], $chapterQuery);
        self::assertFalse($chapter->getNode(0)->hasAttribute('loading'));
        self::assertSame($chapter->attr('src'), $chapter->filter('a')->attr('href'));
        self::assertCount(0, $crawler->filter('details.bookshelf-reader__more'));
    }

    public function testReaderShellRendersTocInlineWithAutomaticChaptersAndOnlyNestedIndexesLazy(): void
    {
        $files = new FilesystemLoader(dirname(__DIR__, 3) . '/templates');
        $files->addPath(dirname(__DIR__, 3) . '/vendor/decent-newsroom/bookshelf-bundle/src/Resources/views', 'Bookshelf');
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['@Bookshelf/bookshelf-layout.html.twig' => '{% block title %}{% endblock %}{% block ogtags %}{% endblock %}{% block body %}{% endblock %}{% block aside %}{% endblock %}']),
            $files,
        ]), ['strict_variables' => true]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $twig->addFunction(new TwigFunction('path', static fn (): string => '/contents'));
        $twig->addFunction(new TwigFunction('ux_icon', static fn (): string => ''));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (): string => 'csrf'));
        $book = [
            'id' => str_repeat('b', 64), 'title' => 'Book', 'type' => 'book', 'authors' => [],
            'summary' => null, 'coverImage' => null, 'language' => null, 'source' => null,
            'releaseDate' => null, 'version' => null,
        ];
        $html = $twig->render('bookshelf/read_nested.html.twig', [
            'app' => ['user' => null, 'request' => ['uri' => 'https://example.test/bookshelf']],
            'book' => $book, 'query' => '', 'contents' => [
                'entries' => [
                    ['position' => 0, 'kind' => 30041, 'title' => 'First', 'invalid' => false],
                    ['position' => 1, 'kind' => 30040, 'title' => 'Part', 'invalid' => false],
                ],
                'hasNext' => false, 'truncated' => false,
            ],
            'rootId' => $book['id'], 'path' => [], 'page' => 1, 'indexMetadata' => null,
            'revisions' => [], 'childSignatures' => [1 => 'index-signature'], 'chapterSignatures' => [0 => 'chapter-signature'],
            'bodySignature' => null,
        ]);
        $crawler = new Crawler($html);
        self::assertCount(0, $crawler->filter('.bookshelf-chapter__content'));
        self::assertCount(2, $crawler->filter('.bookshelf-toc li'));
        self::assertSame('false', $crawler->filter('.bookshelf-toc li a')->first()->attr('data-turbo'));
        self::assertCount(1, $crawler->filter('details.bookshelf-reader__index:not([open]) turbo-frame[loading="lazy"]'));
        $frame = $crawler->filter('turbo-frame#bookshelf-reader-' . $book['id'] . '-root-1');
        self::assertCount(1, $frame);
        self::assertFalse($frame->getNode(0)->hasAttribute('src'));
        self::assertCount(1, $crawler->filter('turbo-frame#bookshelf-chapter-' . $book['id'] . '-root-0[src]:not([loading])'));
    }

    public function testMixedReferencesExpandInOrderIncludingDuplicatesAndCycles(): void
    {
        $pubkey = str_repeat('a', 64);
        $root = $this->event('b', $pubkey, 30040, 'book', [
            ['a', "30041:$pubkey:first"],
            ['a', "30040:$pubkey:part"],
            ['a', "30040:$pubkey:part"],
        ]);
        $part = $this->event('c', $pubkey, 30040, 'part', [
            ['a', "30041:$pubkey:second"],
            ['a', "30040:$pubkey:book"],
        ]);
        $first = $this->event('d', $pubkey, 30041, 'first', [], 'First body');
        $second = $this->event('e', $pubkey, 30041, 'second', [], 'Second body');
        $api = $this->createStub(MercuryApiClientInterface::class);
        $api->method('getPublicationsByAuthors')->willReturn([$part, $root]);
        $api->method('getChaptersByAuthors')->willReturn([$first, $second]);
        $nostr = $this->createMock(NostrClient::class);
        $nostr->expects(self::never())->method('getEventsByCoordinates');
        $service = $this->service($api, $nostr);

        $result = $service->expand([], (object) $root->toArray(), []);

        self::assertSame(['first', 'part', 'second', 'book', 'part', 'second', 'book'], array_column($result['chapters'], 'identifier'));
        self::assertSame([true, true, true, false, true, true, false], array_column($result['chapters'], 'available'));
        self::assertSame([1, 2, 3, 4, 5, 6, 7], array_column($result['chapters'], 'position'));
        self::assertSame('Second body', $result['chapters'][2]['content']);
        self::assertSame(2, $result['missingChapterCount']);

        foreach ($result['chapters'] as &$chapter) {
            $chapter['html'] = htmlspecialchars($chapter['content'] ?? '', ENT_QUOTES);
        }
        unset($chapter);
        $files = new FilesystemLoader();
        $files->addPath(dirname(__DIR__, 3) . '/vendor/decent-newsroom/bookshelf-bundle/src/Resources/views', 'Bookshelf');
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['@Bookshelf/bookshelf-layout.html.twig' => '{% block title %}{% endblock %}{% block ogtags %}{% endblock %}{% block body %}{% endblock %}{% block aside %}{% endblock %}']),
            $files,
        ]), ['strict_variables' => true]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $twig->addFunction(new TwigFunction('path', static fn (): string => '/bookshelf'));
        $twig->addFunction(new TwigFunction('ux_icon', static fn (): string => ''));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (): string => 'csrf'));
        $html = $twig->render('@Bookshelf/bookshelf/read.html.twig', [
            'app' => ['user' => null, 'request' => ['uri' => 'https://example.test/bookshelf']],
            'query' => '', 'book' => $result + [
                'title' => 'Book', 'type' => 'book', 'authors' => [], 'summary' => null,
                'coverImage' => null, 'language' => null, 'source' => null, 'releaseDate' => null, 'version' => null,
            ],
        ]);
        $crawler = new Crawler($html);
        self::assertSame(['first', 'part', 'second', 'book', 'part', 'second', 'book'],
            $crawler->filter('.bookshelf-toc li a')->each(static fn (Crawler $entry): string => $entry->text()));
        self::assertSame('Second body', $crawler->filter('#chapter-3 .bookshelf-chapter__content')->text());
    }

    public function testPinsNeverResolveToAnotherRevisionOrAnotherCoordinate(): void
    {
        $pubkey = str_repeat('a', 64);
        $pin = str_repeat('f', 64);
        $root = $this->event('b', $pubkey, 30040, 'book', [
            ['a', "30040:$pubkey:part", '', $pin],
            ['a', "30040:$pubkey:part", '', 'bad-pin'],
        ]);
        $wrong = $this->event('f', $pubkey, 30040, 'other');
        $api = $this->createStub(MercuryApiClientInterface::class);
        $api->method('getPublicationEventsByIds')->willReturn([$wrong]);
        $nostr = $this->createMock(NostrClient::class);
        $nostr->expects(self::once())->method('getEventsByIds')->with([$pin], [])->willReturn([]);
        $nostr->expects(self::never())->method('getEventsByCoordinates');

        $source = (object) $root->toArray();
        $source->tags[] = ['a', "30040:$pubkey:part", '', null];
        $result = $this->service($api, $nostr)->expand([], $source, []);

        self::assertSame([false, false, false], array_column($result['chapters'], 'available'));
    }

    public function testScopedAndMutedTargetsAreUnavailable(): void
    {
        $pubkey = str_repeat('a', 64);
        $other = str_repeat('b', 64);
        $root = $this->event('c', $pubkey, 30040, 'book', [
            ['a', "30040:$pubkey:scoped"],
            ['a', "30040:$other:muted"],
        ]);
        $scoped = $this->event('d', $pubkey, 30040, 'scoped', [['s', 'private'], ['title', 'Secret']]);
        $muted = $this->event('e', $other, 30040, 'muted', [['title', 'Muted']]);
        $api = $this->createStub(MercuryApiClientInterface::class);
        $api->method('getPublicationsByAuthors')->willReturn([$scoped, $muted]);

        $result = $this->service($api, $this->createStub(NostrClient::class))->expand([], (object) $root->toArray(), [$other]);

        self::assertSame([false, false], array_column($result['chapters'], 'available'));
        self::assertStringNotContainsString('Secret', serialize($result));
        self::assertStringNotContainsString('Muted', serialize($result));
    }

    public function testNestedIndexesAndChaptersFallBackToRelayHints(): void
    {
        $pubkey = str_repeat('a', 64);
        $root = $this->event('b', $pubkey, 30040, 'book', [['a', "30040:$pubkey:part", 'wss://books.example']]);
        $part = $this->event('c', $pubkey, 30040, 'part', [['a', "30041:$pubkey:section", 'wss://books.example']]);
        $section = $this->event('d', $pubkey, 30041, 'section', [], 'Relay text');
        $api = $this->createStub(MercuryApiClientInterface::class);
        $nostr = $this->createMock(NostrClient::class);
        $nostr->expects(self::exactly(2))->method('getEventsByCoordinates')->willReturnCallback(
            static function (array $coordinates, array $relays) use ($pubkey, $part, $section): array {
                self::assertSame(['wss://books.example'], $relays);
                return match ($coordinates) {
                    ["30040:$pubkey:part"] => [(object) $part->toArray()],
                    ["30041:$pubkey:section"] => [(object) $section->toArray()],
                    default => self::fail('Unexpected coordinates'),
                };
            },
        );

        $result = $this->service($api, $nostr)->expand([], (object) $root->toArray(), []);

        self::assertSame(['part', 'section'], array_column($result['chapters'], 'identifier'));
        self::assertSame('Relay text', $result['chapters'][1]['content']);
    }

    public function testBooksApiResolvesRootAndNestedContentsBeforeMercuryOrRelays(): void
    {
        $pubkey = str_repeat('a', 64);
        $root = $this->event('b', $pubkey, 30040, 'book', [['a', "30040:$pubkey:part"]]);
        $part = $this->event('c', $pubkey, 30040, 'part', [['a', "30041:$pubkey:section"]]);
        $section = $this->event('d', $pubkey, 30041, 'section', [], 'Section text');
        $books = $this->createMock(MercuryApiClientInterface::class);
        $books->expects(self::once())->method('getEvent')->with($root->id)->willReturn($root);
        $books->expects(self::once())->method('getPublicationsByAuthors')
            ->with([$pubkey], 1, ['part'])->willReturn([$part]);
        $books->expects(self::once())->method('getChaptersByAuthors')
            ->with([$pubkey], 1, ['section'])->willReturn([$section]);
        $mercury = $this->createMock(MercuryApiClientInterface::class);
        $mercury->expects(self::never())->method('getEvent');
        $mercury->expects(self::never())->method('getPublicationsByAuthors');
        $mercury->expects(self::never())->method('getChaptersByAuthors');
        $nostr = $this->createMock(NostrClient::class);
        $nostr->expects(self::never())->method('getEventsByIds');
        $nostr->expects(self::never())->method('getEventsByCoordinates');
        $service = new BookshelfReaderContents(
            $mercury, $nostr, $this->events(), $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(DeletedEventRepository::class), $this->createStub(HiddenCoordinateRepository::class),
            $this->createStub(LoggerInterface::class), $books,
        );

        $source = $service->source($root->id);
        self::assertNotNull($source);
        $result = $service->expand([], $source, []);
        self::assertSame(['part', 'section'], array_column($result['chapters'], 'identifier'));
        self::assertSame('Section text', $result['chapters'][1]['content']);
    }

    public function testBooksApiRetriesMissingCoordinatesWhenBatchContainsRevisions(): void
    {
        $pubkey = str_repeat('a', 64);
        $root = $this->event('b', $pubkey, 30040, 'book', [
            ['a', "30040:$pubkey:part"],
            ['a', "30041:$pubkey:first"],
            ['a', "30041:$pubkey:second"],
        ]);
        $part = $this->event('c', $pubkey, 30040, 'part', [['a', "30041:$pubkey:first"]]);
        $first = $this->event('d', $pubkey, 30041, 'first');
        $old = $this->event('e', $pubkey, 30041, 'first');
        $second = $this->event('f', $pubkey, 30041, 'second');
        $books = $this->createMock(MercuryApiClientInterface::class);
        $books->expects(self::once())->method('getPublicationsByAuthors')->willReturn([$part]);
        $books->expects(self::exactly(3))->method('getChaptersByAuthors')
            ->willReturnCallback(static function (array $authors, int $limit, array $identifiers) use ($pubkey, $first, $old, $second): array {
                self::assertSame([$pubkey], $authors);
                return match ($identifiers) {
                    ['first', 'second'] => [$first, $old],
                    ['second'] => [$second],
                    ['first'] => [$first],
                    default => self::fail('Unexpected chapter coordinates.'),
                };
            });
        $mercury = $this->createMock(MercuryApiClientInterface::class);
        $mercury->expects(self::never())->method('getChaptersByAuthors');
        $nostr = $this->createMock(NostrClient::class);
        $nostr->expects(self::never())->method('getEventsByCoordinates');
        $service = new BookshelfReaderContents(
            $mercury, $nostr, $this->events(), $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(DeletedEventRepository::class), $this->createStub(HiddenCoordinateRepository::class),
            $this->createStub(LoggerInterface::class), $books,
        );

        $result = $service->expand([], (object) $root->toArray(), []);

        self::assertSame(['part', 'first', 'first', 'second'], array_column($result['chapters'], 'identifier'));
        self::assertSame(4, $result['availableChapterCount']);
        self::assertSame(0, $result['missingChapterCount']);
    }

    public function testLocalIndexAndTargetsRemainReadableWhenRemoteSourcesAreEmpty(): void
    {
        $pubkey = str_repeat('a', 64);
        $root = $this->event('b', $pubkey, 30040, 'book', [['a', "30040:$pubkey:part"]]);
        $part = $this->event('c', $pubkey, 30040, 'part', [['title', 'Local part']]);
        $events = $this->createStub(EventRepository::class);
        $events->method('findByIds')->willReturn([$root->id => $this->entity($root)]);
        $events->method('findBookshelfReaderTargets')->willReturn([
            'coordinates' => ["30040:$pubkey:part" => $this->entity($part)], 'eventIds' => [],
        ]);
        $api = $this->createMock(MercuryApiClientInterface::class);
        $api->expects(self::never())->method('getEvent');
        $api->expects(self::never())->method('getPublicationsByAuthors');
        $api->expects(self::never())->method('getChaptersByAuthors');
        $api->expects(self::never())->method('getPublicationEventsByIds');
        $api->expects(self::never())->method('getEventsByIds');
        $nostr = $this->createMock(NostrClient::class);
        $nostr->expects(self::never())->method('getEventsByCoordinates');
        $service = new BookshelfReaderContents(
            $api, $nostr, $events, $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(DeletedEventRepository::class), $this->createStub(HiddenCoordinateRepository::class),
            $this->createStub(LoggerInterface::class), $api,
        );

        $source = $service->source($root->id);
        self::assertNotNull($source);
        $result = $service->expand([], $source, []);
        self::assertSame('Local part', $result['chapters'][0]['title']);
        self::assertTrue($result['chapters'][0]['available']);
    }

    public function testOnlyMissingTargetsAreSentToRemoteSources(): void
    {
        $pubkey = str_repeat('a', 64);
        $root = $this->event('b', $pubkey, 30040, 'book', [
            ['a', "30040:$pubkey:local"],
            ['a', "30040:$pubkey:remote"],
        ]);
        $local = $this->event('c', $pubkey, 30040, 'local');
        $remote = $this->event('d', $pubkey, 30040, 'remote');
        $events = $this->createStub(EventRepository::class);
        $events->method('findBookshelfReaderTargets')->willReturn([
            'coordinates' => ["30040:$pubkey:local" => $this->entity($local)], 'eventIds' => [],
        ]);
        $api = $this->createMock(MercuryApiClientInterface::class);
        $api->expects(self::once())->method('getPublicationsByAuthors')
            ->with([$pubkey], 1, ['remote'])->willReturn([$remote]);
        $nostr = $this->createMock(NostrClient::class);
        $nostr->expects(self::never())->method('getEventsByCoordinates');
        $service = new BookshelfReaderContents(
            $this->createStub(MercuryApiClientInterface::class), $nostr, $events, $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(DeletedEventRepository::class), $this->createStub(HiddenCoordinateRepository::class),
            $this->createStub(LoggerInterface::class), $api,
        );

        $result = $service->expand([], (object) $root->toArray(), []);

        self::assertSame(['local', 'remote'], array_column($result['chapters'], 'identifier'));
        self::assertSame([true, true], array_column($result['chapters'], 'available'));
    }

    public function testReaderLimitsOccurrencesAndReportsTruncation(): void
    {
        $pubkey = str_repeat('a', 64);
        $tags = array_fill(0, 501, ['a', "30040:$pubkey:part"]);
        $root = $this->event('b', $pubkey, 30040, 'book', $tags);
        $part = $this->event('c', $pubkey, 30040, 'part');
        $api = $this->createStub(MercuryApiClientInterface::class);
        $api->method('getPublicationsByAuthors')->willReturn([$part]);

        $result = $this->service($api, $this->createStub(NostrClient::class))->expand([], (object) $root->toArray(), []);

        self::assertCount(500, $result['chapters']);
        self::assertTrue($result['truncated']);
        self::assertSame(500, $result['availableChapterCount']);
    }

    public function testMalformedReferencesRemainVisibleWithoutFetchingThem(): void
    {
        $pubkey = str_repeat('a', 64);
        $root = $this->event('b', $pubkey, 30040, 'book', [['a', '30040:not-a-pubkey:part']]);
        $nostr = $this->createMock(NostrClient::class);
        $nostr->expects(self::never())->method('getEventsByCoordinates');
        $nostr->expects(self::never())->method('getEventsByIds');
        $api = $this->createMock(MercuryApiClientInterface::class);
        $api->expects(self::never())->method('getPublicationsByAuthors');

        $result = $this->service($api, $nostr)->expand([], (object) $root->toArray(), []);

        self::assertSame('part', $result['chapters'][0]['title']);
        self::assertFalse($result['chapters'][0]['available']);
    }

    public function testTraversalStopsAtSixLevels(): void
    {
        $pubkey = str_repeat('a', 64);
        $events = [];
        for ($i = 0; $i <= 7; ++$i) {
            $events[] = $this->event((string) $i, $pubkey, 30040, "part-$i", [
                ['a', "30040:$pubkey:part-" . ($i + 1)],
            ]);
        }
        $api = $this->createStub(MercuryApiClientInterface::class);
        $api->method('getPublicationsByAuthors')->willReturn($events);

        $result = $this->service($api, $this->createStub(NostrClient::class))->expand([], (object) $events[0]->toArray(), []);

        self::assertCount(6, $result['chapters']);
        self::assertSame('part-6', $result['chapters'][5]['identifier']);
        self::assertTrue($result['truncated']);
    }

    public function testRelayFallbackLimitsGroupsAndHintsWithoutHidingReferences(): void
    {
        $pubkey = str_repeat('a', 64);
        $tags = [];
        for ($i = 1; $i <= 21; ++$i) {
            $tags[] = ['a', '30040:' . sprintf('%064x', $i) . ':part', "wss://relay$i.example"];
        }
        $root = $this->event('b', $pubkey, 30040, 'book', $tags);
        $nostr = $this->createMock(NostrClient::class);
        $nostr->expects(self::once())->method('getEventsByCoordinates')->willReturnCallback(
            static function (array $coordinates, array $relays): array {
                self::assertCount(20, $coordinates);
                self::assertCount(5, $relays);
                return [];
            },
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Bookshelf relay lookup reached its author and kind limit.', ['remaining_coordinates' => 1],
        );
        $service = new BookshelfReaderContents(
            $this->createStub(MercuryApiClientInterface::class), $nostr, $this->events(),
            $this->createStub(ContentAuthorAccessPolicy::class), $this->createStub(DeletedEventRepository::class),
            $this->createStub(HiddenCoordinateRepository::class), $logger, $this->createStub(MercuryApiClientInterface::class),
        );

        $result = $service->expand([], (object) $root->toArray(), []);

        self::assertCount(21, $result['chapters']);
        self::assertSame(21, $result['missingChapterCount']);
    }

    public function testHiddenAndDeletedTargetsAreNotDisclosed(): void
    {
        $pubkey = str_repeat('a', 64);
        $root = $this->event('b', $pubkey, 30040, 'book', [
            ['a', "30040:$pubkey:hidden"], ['a', "30040:$pubkey:deleted"],
        ]);
        $hidden = $this->event('c', $pubkey, 30040, 'hidden', [['title', 'Hidden title']]);
        $deleted = $this->event('d', $pubkey, 30040, 'deleted', [['title', 'Deleted title']]);
        $api = $this->createStub(MercuryApiClientInterface::class);
        $api->method('getPublicationsByAuthors')->willReturn([$hidden, $deleted]);
        $hiddenRepository = $this->createStub(HiddenCoordinateRepository::class);
        $hiddenRepository->method('findHiddenCoordinates')->willReturn(["30040:$pubkey:hidden"]);
        $deletedRepository = $this->createStub(DeletedEventRepository::class);
        $deletedRepository->method('findSuppressedEventIds')->willReturn([str_repeat('d', 64)]);
        $service = new BookshelfReaderContents(
            $api, $this->createStub(NostrClient::class), $this->events(), $this->createStub(ContentAuthorAccessPolicy::class),
            $deletedRepository, $hiddenRepository, $this->createStub(LoggerInterface::class), $this->createStub(MercuryApiClientInterface::class),
        );

        $result = $service->expand([], (object) $root->toArray(), []);

        self::assertSame([false, false], array_column($result['chapters'], 'available'));
        self::assertStringNotContainsString('Hidden title', serialize($result));
        self::assertStringNotContainsString('Deleted title', serialize($result));
    }

    private function service(MercuryApiClientInterface $api, NostrClient $nostr): BookshelfReaderContents
    {
        return new BookshelfReaderContents(
            $this->createStub(MercuryApiClientInterface::class), $nostr, $this->events(), $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(DeletedEventRepository::class), $this->createStub(HiddenCoordinateRepository::class),
            $this->createStub(LoggerInterface::class), $api,
        );
    }

    private function events(): EventRepository
    {
        $events = $this->createStub(EventRepository::class);
        $events->method('findBookshelfReaderTargets')->willReturn(['coordinates' => [], 'eventIds' => []]);
        return $events;
    }

    private function entity(MercuryEvent $event): Event
    {
        $entity = new Event();
        $entity->setId($event->id);
        $entity->setKind($event->kind);
        $entity->setPubkey($event->pubkey);
        $entity->setCreatedAt($event->createdAt);
        $entity->setTags($event->tags);
        $entity->setContent($event->content);
        return $entity;
    }

    /** @param list<list<string>> $tags */
    private function event(string $id, string $pubkey, int $kind, string $identifier, array $tags = [], string $content = ''): MercuryEvent
    {
        return new MercuryEvent(str_repeat($id, 64), $pubkey, $kind, 1, $content, '', [['d', $identifier], ...$tags]);
    }
}
