<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bookshelf;

use App\Bookshelf\BookshelfReaderContents;
use App\Entity\Event;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\Nostr\NostrClient;
use App\Service\Reader\ContentAuthorAccessPolicy;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryApiClientInterface;
use DecentNewsroom\BookshelfBundle\Service\Mercury\Model\MercuryEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class BookshelfReaderContentsTest extends TestCase
{
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
            ->with([$pubkey], 500, ['part'])->willReturnCallback(
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
        $page = $service->childrenPage($source, [], 1);
        self::assertCount(50, $page['entries']);
        self::assertTrue($page['hasNext']);
        $last = $service->childrenPage($source, [], 10);
        self::assertFalse($last['hasNext']);
        self::assertTrue($last['truncated']);

        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'), ['strict_variables' => true]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $twig->addFunction(new TwigFunction('path', static fn (): string => '/contents'));
        $html = $twig->render('bookshelf/_reader_contents.html.twig', [
            'rootId' => str_repeat('b', 64), 'path' => [], 'page' => 1,
            'contents' => $page, 'indexBody' => null, 'indexMetadata' => null,
        ]);
        $crawler = new Crawler($html);
        self::assertCount(50, $crawler->filter('details.bookshelf-reader__index:not([open])'));
        self::assertCount(50, $crawler->filter('details.bookshelf-reader__index turbo-frame[loading="lazy"]'));
        self::assertCount(50, $crawler->filter('.bookshelf-toc li'));
    }

    public function testNestedReaderTemplateIncludesFirstPageWithoutOpeningIndexes(): void
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
                    ['position' => 0, 'kind' => 30041, 'title' => 'First', 'summary' => null, 'available' => true, 'html' => '<p>Text</p>'],
                    ['position' => 1, 'kind' => 30040, 'title' => 'Part', 'invalid' => false],
                ],
                'hasNext' => false, 'truncated' => false,
            ],
        ]);
        $crawler = new Crawler($html);
        self::assertSame('Text', $crawler->filter('.bookshelf-chapter__content')->text());
        self::assertSame('Part', $crawler->filter('details.bookshelf-reader__index:not([open]) > summary')->text());
        self::assertCount(2, $crawler->filter('.bookshelf-toc li'));
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
            ->with([$pubkey], 500, ['part'])->willReturn([$part]);
        $books->expects(self::once())->method('getChaptersByAuthors')
            ->with([$pubkey], 500, ['section'])->willReturn([$section]);
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
            ->with([$pubkey], 500, ['remote'])->willReturn([$remote]);
        $nostr = $this->createMock(NostrClient::class);
        $nostr->expects(self::never())->method('getEventsByCoordinates');
        $service = new BookshelfReaderContents(
            $api, $nostr, $events, $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(DeletedEventRepository::class), $this->createStub(HiddenCoordinateRepository::class),
            $this->createStub(LoggerInterface::class), $this->createStub(MercuryApiClientInterface::class),
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
            $api, $nostr, $this->events(), $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(DeletedEventRepository::class), $this->createStub(HiddenCoordinateRepository::class),
            $this->createStub(LoggerInterface::class), $this->createStub(MercuryApiClientInterface::class),
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
