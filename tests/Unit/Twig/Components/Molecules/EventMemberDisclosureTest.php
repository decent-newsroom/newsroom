<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components\Molecules;

use App\Twig\Components\Atoms\PageHeading;
use App\Twig\Components\Molecules\BookshelfDirectoryDisclosure;
use App\Twig\Components\Molecules\EventBookmarkDisclosure;
use App\Twig\Components\Molecules\EventMemberDisclosure;
use App\Twig\Components\Molecules\EventTocDisclosure;
use App\Twig\Components\Molecules\LazyDisclosure;
use App\Entity\Event;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventWorkspaceSourceAdapter;
use App\Service\EventWorkspace\PublicationTocPage;
use App\Service\EventWorkspace\PublicationTocTraversalSigner;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\ContentAuthorAccessPolicy;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\TwigFunction;

final class EventMemberDisclosureTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new EventMemberDisclosureTestKernel('test', true);
    }

    public function testDisclosureIsNativeSeparateAndLazyWithFallbackLink(): void
    {
        $crawler = $this->renderTwigComponent('Molecules:EventMemberDisclosure', [
            'eventId' => self::ID,
            'title' => 'People <unsafe>',
            'url' => '/members',
        ])->crawler();

        self::assertSame('Members of People <unsafe> (npubs)', $crawler->filter('details > summary')->text());
        self::assertSame(0, $crawler->filter('details[open], details[name], .reading-nook-menu')->count());
        self::assertSame('lazy', $crawler->filter('turbo-frame')->attr('loading'));
        self::assertSame('nook-members-' . self::ID, $crawler->filter('turbo-frame')->attr('id'));
        self::assertSame('/members', $crawler->filter('turbo-frame')->attr('src'));
        self::assertSame('/members', $crawler->filter('turbo-frame a')->attr('href'));
        self::assertSame(0, $crawler->filter('unsafe')->count());
    }

    public function testFragmentPreservesOccurrencesAndEscapesInvalidValues(): void
    {
        self::bootKernel();
        $html = self::getContainer()->get('twig')->render('reader/reading_nook/_follow_pack_members.html.twig', [
            'memberPage' => [
                'eventId' => self::ID, 'page' => 2, 'hasPrevious' => true, 'hasNext' => true,
                'members' => [
                    ['position' => 60, 'npub' => 'npub-duplicate', 'rawValue' => 'raw'],
                    ['position' => 61, 'npub' => null, 'rawValue' => '<script>alert(1)</script>'],
                    ['position' => 62, 'npub' => 'npub-duplicate', 'rawValue' => 'raw'],
                    ['position' => 63, 'npub' => null, 'rawValue' => null],
                ],
            ],
        ]);
        $crawler = new Crawler($html);
        self::assertSame('nook-members-' . self::ID, $crawler->filter('turbo-frame')->attr('id'));
        self::assertSame('51', $crawler->filter('ol')->attr('start'));
        self::assertSame(['60', '61', '62', '63'], $crawler->filter('li')->extract(['data-source-tag-position']));
        self::assertSame(2, $crawler->filter('li:contains("npub-duplicate")')->count());
        self::assertSame(2, $crawler->filter('li:contains("Invalid member reference")')->count());
        self::assertSame(0, $crawler->filter('script, img, iframe')->count());
        self::assertStringContainsString('page=1', $crawler->filter('a[rel="prev"]')->attr('href'));
        self::assertStringContainsString('page=3', $crawler->filter('a[rel="next"]')->attr('href'));
        self::assertSame('Member pages', $crawler->filter('nav')->attr('aria-label'));
    }

    public function testTocDisclosureIsSeparateNativeLazyAndEscaped(): void
    {
        $crawler = $this->renderTwigComponent('Molecules:EventTocDisclosure', [
            'eventId' => self::ID,
            'title' => 'Book <unsafe>',
            'url' => '/toc',
        ])->crawler();

        self::assertSame('Table of contents of Book <unsafe>', $crawler->filter('details > summary')->text());
        self::assertSame(0, $crawler->filter('details[open], details[name], unsafe')->count());
        self::assertSame('lazy', $crawler->filter('turbo-frame')->attr('loading'));
        self::assertSame('nook-toc-' . self::ID, $crawler->filter('turbo-frame')->attr('id'));
        self::assertSame('/toc', $crawler->filter('turbo-frame')->attr('src'));
        self::assertSame('/toc', $crawler->filter('turbo-frame a')->attr('href'));
    }

    public function testBookmarkDisclosureIsSeparateNativeLazyAndEscaped(): void
    {
        $crawler = $this->renderTwigComponent('Molecules:EventBookmarkDisclosure', [
            'eventId' => self::ID,
            'title' => 'Saved <unsafe>',
            'url' => '/bookmarks',
        ])->crawler();

        self::assertSame('Bookmarks in Saved <unsafe>', $crawler->filter('details > summary')->text());
        self::assertSame(0, $crawler->filter('details[open], details[name], unsafe')->count());
        self::assertSame('lazy', $crawler->filter('turbo-frame')->attr('loading'));
        self::assertSame('nook-bookmarks-' . self::ID, $crawler->filter('turbo-frame')->attr('id'));
        self::assertSame('/bookmarks', $crawler->filter('turbo-frame')->attr('src'));
        self::assertSame('/bookmarks', $crawler->filter('turbo-frame a')->attr('href'));
    }

    public function testBookshelfDisclosureIsNativeLazyAndHasFallbackLink(): void
    {
        $crawler = $this->renderTwigComponent('Molecules:BookshelfDirectoryDisclosure', [
            'url' => '/bookshelf/my-books/references',
        ])->crawler();

        self::assertSame('Saved directory references', $crawler->filter('details > summary')->text());
        self::assertSame(0, $crawler->filter('details[open], details[name]')->count());
        self::assertSame('lazy', $crawler->filter('turbo-frame')->attr('loading'));
        self::assertSame('bookshelf-directory-references', $crawler->filter('turbo-frame')->attr('id'));
        self::assertSame('/bookshelf/my-books/references', $crawler->filter('turbo-frame a')->attr('href'));
    }

    /** @dataProvider expansionFlags */
    public function testMyContentDisclosurePreservesAuthoringActionsAndIsSeparateAndLazy(bool $enabled): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('event_workspace_expansion_enabled', $enabled);
        $twig->setLoader(new ChainLoader([
            new ArrayLoader(['newsroom-layout.html.twig' => '{% block body %}{% endblock %}']),
            $twig->getLoader(),
        ]));
        $twig->addFunction(new TwigFunction('ux_icon', static fn (): string => ''));
        $twig->addFilter(new \Twig\TwigFilter('toNpub', static fn (string $key): string => 'npub-owner'));
        $article = (new \App\Entity\Article())->setEventId(self::ID)->setPubkey(str_repeat('b', 64))
            ->setSlug('article')->setKind(30023);
        $draft = (new \App\Entity\Article())->setEventId(str_repeat('d', 64))->setPubkey(str_repeat('b', 64))
            ->setSlug('draft')->setKind(30024);
        $local = (new \App\Entity\Article())->setPubkey(str_repeat('b', 64))->setSlug('local')->setKind(30024);
        $items = [];
        foreach ([$article, $draft, $local] as $source) {
            $items[] = [
                'article' => $source, 'category' => $source === $article ? 'published' : 'drafts',
                'title' => 'Title <script>', 'summary' => null, 'updatedAt' => null,
            ];
        }
        $crawler = new Crawler($twig->render('my_content/index.html.twig', [
            'contentItems' => $items, 'activeType' => 'all', 'searchQuery' => 'search',
            'sort' => 'title', 'counts' => ['all' => 3, 'published' => 1, 'drafts' => 2],
            'filteredCount' => 3, 'page' => 1, 'totalPages' => 1,
        ]));
        self::assertCount(3, $crawler->filter('article.my-content-row'));
        self::assertCount(3, $crawler->filter('details.my-content-menu[name="my-content-actions"]'));
        self::assertCount($enabled ? 2 : 0, $crawler->filter('details.my-content-row__preview'));
        self::assertCount(0, $crawler->filter('.my-content-menu .my-content-row__preview, .my-content-row__preview[name], script'));
        if ($enabled) {
            self::assertSame('lazy', $crawler->filter('.my-content-row__preview turbo-frame')->first()->attr('loading'));
            self::assertSame('/my-content/preview/' . self::ID, $crawler->filter('.my-content-row__preview turbo-frame')->first()->attr('src'));
            self::assertSame('/my-content/preview/' . self::ID, $crawler->filter('.my-content-row__preview a')->first()->attr('href'));
        } else {
            self::assertCount(0, $crawler->filter('turbo-frame'));
        }
        self::assertSame('/editor/article', $crawler->filter('.my-content-row h2 a')->first()->attr('href'));
        self::assertSame('/editor/draft/draft', $crawler->filter('.my-content-row h2 a')->eq(1)->attr('href'));
        self::assertCount(1, $crawler->filter('button[data-event-id="' . self::ID . '"]'));
        self::assertCount(3, $crawler->filter('[data-controller="utility--copy-to-clipboard"]'));
        self::assertSame('search', $crawler->filter('input[name="q"]')->attr('value'));
    }

    public function testMyContentPreviewEscapesReferencesAndGatesSensitiveMetadata(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        $factory = new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service());
        $presentation = $factory->fromAuthorizedFields(
            self::ID, 30024, str_repeat('b', 64), 123,
            [['d', 'draft'], ['title', '<script>Title</script>'], ['e', str_repeat('c', 64)],
                ['e', '<img src=x>'], ['e', str_repeat('c', 64)]],
            '<iframe>Never render body</iframe>',
        );
        $parameters = [
            'eventId' => self::ID, 'presentation' => $presentation,
            'references' => $presentation->references, 'page' => 2, 'totalPages' => 3,
        ];
        $html = $twig->render('my_content/_preview.html.twig', $parameters);
        $crawler = new Crawler($html);
        self::assertSame('my-content-preview-' . self::ID, $crawler->filter('turbo-frame')->attr('id'));
        self::assertSame(['2', '3', '4'], $crawler->filter('li')->extract(['data-source-tag-position']));
        self::assertCount(2, $crawler->filter('li:contains("' . str_repeat('c', 64) . '")'));
        self::assertSame('51', $crawler->filter('ol')->attr('start'));
        self::assertCount(0, $crawler->filter('script, img, iframe, li a'));
        self::assertStringContainsString('Invalid reference', $crawler->text());
        self::assertStringContainsString('page=1', $crawler->filter('[rel="prev"]')->attr('href'));
        self::assertStringContainsString('page=3', $crawler->filter('[rel="next"]')->attr('href'));
        $parameters['presentation'] = $factory->fromAuthorizedFields(
            self::ID, 30024, str_repeat('b', 64), 123,
            [['d', 'draft'], ['title', 'Secret title'], ['content-warning', 'warning']], 'Secret body',
        );
        $html = $twig->render('my_content/_preview.html.twig', $parameters);
        self::assertStringNotContainsString('Secret', $html);
        self::assertStringNotContainsString('data-source-tag-position', $html);
        $parameters['presentation'] = null;
        $crawler = new Crawler($twig->render('my_content/_preview.html.twig', $parameters));
        self::assertStringContainsString('The original event is unavailable locally.', $crawler->text());
        self::assertCount(0, $crawler->filter('ol'));
    }

    public function testBookDetailLinksToContinuousReaderAndEscapedContainingIndexes(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        $twig->setLoader(new ChainLoader([
            new ArrayLoader([
                '@Bookshelf/bookshelf-layout.html.twig' => '{% block body %}{% endblock %}',
                'components/Molecules/UserFromNpub.html.twig' => '{% props ident %}<span>{{ ident }}</span>',
            ]),
            $twig->getLoader(),
        ]));
        $twig->addFunction(new TwigFunction('ux_icon', static fn (): string => ''));
        $twig->addFunction(new TwigFunction('is_granted', static fn (): bool => false));
        $publication = new Event();
        $publication->setId(self::ID);
        $publication->setPubkey(str_repeat('b', 64));
        $publication->setKind(30040);
        $publication->setTags([['d', 'book'], ['title', 'Book']]);
        $parameters = [
            'app' => ['user' => null],
            'publication' => $publication, 'book' => 'book', 'publicationType' => 'book',
            'metadata' => [], 'chapters' => [[
                'event' => ['title' => 'Chapter', 'slug' => 'chapter'],
                'coordinate' => '30041:' . str_repeat('b', 64) . ':chapter',
            ]],
            'containingIndexes' => [['title' => '<script>Parent</script>', 'naddr' => 'naddr1parent']],
        ];
        $crawler = new Crawler($twig->render('bookshelf/publication.html.twig', $parameters));
        self::assertSame('/bookshelf/' . self::ID, $crawler->selectLink('Read this book')->attr('href'));
        self::assertSame('Contained in index', $crawler->filter('#publication-containing-indexes')->text());
        self::assertSame('/e/naddr1parent', $crawler->selectLink('<script>Parent</script>')->attr('href'));
        self::assertSame(0, $crawler->filter('script')->count());
        self::assertSame('Chapter', $crawler->filter('.bookshelf-publication__chapters li')->text());
        self::assertSame('/magazine/book/chapter/chapter', $crawler->selectLink('Chapter')->attr('href'));
        $parameters['containingIndexes'] = [];
        $crawler = new Crawler($twig->render('bookshelf/publication.html.twig', $parameters));
        self::assertSame(0, $crawler->filter('#publication-containing-indexes')->count());
        self::assertCount(1, $crawler->selectLink('Read this book'));
    }

    public function testBookshelfFragmentKeepsOccurrencesPinsAndEscapesInvalidValues(): void
    {
        self::bootKernel();
        $event = new Event();
        $event->setId(self::ID);
        $event->setPubkey(str_repeat('b', 64));
        $event->setKind(30045);
        $event->setTags([
            ['d', 'my-book-collection'],
            ['a', '30040:' . str_repeat('b', 64) . ':book', 'javascript:untrusted', str_repeat('c', 64)],
            ['e', str_repeat('d', 64)],
            ['e', str_repeat('d', 64)],
            ['a', '<script>alert(1)</script>'],
            ['a', '30040:' . str_repeat('b', 64) . ':book', '', '<img src=x>'],
        ]);
        $event->setContent('never render directory body');
        $identities = new RecordIdentityService();
        $pages = new \App\Service\EventWorkspace\BookshelfReferencePage(
            new EventWorkspaceSourceAdapter($identities),
            new EventPresentationFactory($identities, new NostrNip19Service()),
        );
        $page = $pages->fromAuthorizedEvent($event);
        $page['hasNext'] = true;
        $html = self::getContainer()->get('twig')->render('bookshelf/_directory_references.html.twig', [
            'directoryPage' => $page,
        ]);
        $crawler = new Crawler($html);
        self::assertSame(['1', '2', '3', '4', '5'], $crawler->filter('li')->extract(['data-source-tag-position']));
        self::assertSame(2, $crawler->filter('li:contains("' . str_repeat('d', 64) . '")')->count());
        self::assertStringContainsString('Exact event revision: ' . str_repeat('c', 64), $crawler->text());
        self::assertStringContainsString('Invalid version pin: <img src=x>', $crawler->text());
        self::assertSame(0, $crawler->filter('script, img, iframe, li a')->count());
        self::assertStringNotContainsString('javascript:untrusted', $html);
        self::assertStringNotContainsString('never render directory body', $html);
        self::assertStringContainsString('eventId=' . self::ID, $crawler->filter('a[rel="next"]')->attr('href'));
        self::assertStringContainsString('page=2', $crawler->filter('a[rel="next"]')->attr('href'));
    }

    public function testBookshelfMissingLocalDirectoryShowsEmptyFrameWithoutSyntheticIdentity(): void
    {
        self::bootKernel();
        $html = self::getContainer()->get('twig')->render('bookshelf/_directory_references.html.twig', [
            'directoryPage' => null,
        ]);
        $crawler = new Crawler($html);
        self::assertSame('bookshelf-directory-references', $crawler->filter('turbo-frame')->attr('id'));
        self::assertStringContainsString('No directory references on this page.', $crawler->text());
        self::assertSame(0, $crawler->filter('li, nav, a')->count());
    }

    /** @dataProvider expansionFlags */
    public function testMyBooksOverridePreservesBundleCardsAndActions(bool $enabled): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('event_workspace_expansion_enabled', $enabled);
        $vendorTemplate = file_get_contents(self::getContainer()->getParameter('kernel.project_dir')
            . '/vendor/decent-newsroom/bookshelf-bundle/src/Resources/views/bookshelf/my_books.html.twig');
        self::assertIsString($vendorTemplate);
        $twig->setLoader(new ChainLoader([
            new ArrayLoader([
                '@!Bookshelf/bookshelf/my_books.html.twig' => $vendorTemplate,
                '@Bookshelf/bookshelf-layout.html.twig' => '{% block body %}{% endblock %}',
            ]),
            $twig->getLoader(),
        ]));
        $twig->addFunction(new TwigFunction('ux_icon', static fn (): string => ''));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (): string => 'test-token'));
        $crawler = new Crawler($twig->render('bundles/BookshelfBundle/bookshelf/my_books.html.twig', [
            'directoryIdentifier' => 'my-book-collection', 'directoryTags' => [],
            'available' => true, 'missingBookCount' => 0,
            'books' => [[
                'id' => self::ID, 'coordinate' => '30040:' . self::ID . ':book',
                'coverImage' => null, 'title' => 'Saved book', 'type' => 'book',
                'authors' => ['Writer'], 'summary' => 'Summary', 'chapterCount' => 2,
                'language' => null, 'releaseDate' => null, 'version' => null,
            ]],
        ]));
        self::assertSame(1, $crawler->filter('.bookshelf-book')->count());
        self::assertSame('Saved book', $crawler->filter('.bookshelf-book h2')->text());
        self::assertSame(1, $crawler->filter('.bookshelf-book__open')->count());
        self::assertSame(1, $crawler->filter('button[data-action="click->ui--bookshelf-directory#toggle"]')->count());
        self::assertSame($enabled ? 1 : 0, $crawler->filter('details.bookshelf-directory-disclosure')->count());
        self::assertSame(0, $crawler->filter('.bookshelf-book .bookshelf-directory-disclosure')->count());
        if ($enabled) {
            self::assertCount(1, $crawler->filter('#bookshelf-directory-references'));
        } else {
            self::assertCount(0, $crawler->filter('turbo-frame'));
        }
    }

    public function testBookmarkFragmentPreservesOrderedReferencesAndEscapesValues(): void
    {
        self::bootKernel();
        $html = self::getContainer()->get('twig')->render('reader/reading_nook/_bookmark_references.html.twig', [
            'bookmarkPage' => [
                'eventId' => self::ID, 'page' => 2, 'hasPrevious' => true, 'hasNext' => true,
                'entries' => [
                    ['position' => 60, 'type' => 'event', 'value' => 'note-duplicate', 'rawValue' => 'raw', 'invalid' => false],
                    ['position' => 61, 'type' => 'invalid', 'value' => '<script>alert(1)</script>', 'rawValue' => '<script>alert(1)</script>', 'invalid' => true],
                    ['position' => 62, 'type' => 'event', 'value' => 'note-duplicate', 'rawValue' => 'raw', 'invalid' => false],
                ],
            ],
        ]);
        $crawler = new Crawler($html);
        self::assertSame('nook-bookmarks-' . self::ID, $crawler->filter('turbo-frame')->attr('id'));
        self::assertSame('51', $crawler->filter('ol')->attr('start'));
        self::assertSame(['60', '61', '62'], $crawler->filter('li')->extract(['data-source-tag-position']));
        self::assertSame(2, $crawler->filter('li:contains("note-duplicate")')->count());
        self::assertSame(1, $crawler->filter('li:contains("Invalid bookmark reference")')->count());
        self::assertSame(0, $crawler->filter('script, img, iframe, li a')->count());
        self::assertStringContainsString('page=1', $crawler->filter('a[rel="prev"]')->attr('href'));
        self::assertStringContainsString('page=3', $crawler->filter('a[rel="next"]')->attr('href'));
        self::assertSame('Bookmark pages', $crawler->filter('nav')->attr('aria-label'));
    }

    public function testTocFragmentRendersOrderedUnresolvedNestedAndInvalidReferencesNotBodiesOrActions(): void
    {
        self::bootKernel();
        $coordinate = '30041:' . str_repeat('b', 64) . ':chapter<script>';
        $pin = str_repeat('c', 64);
        $event = new Event();
        $event->setId(self::ID);
        $event->setPubkey(str_repeat('b', 64));
        $event->setKind(30040);
        $event->setCreatedAt(123);
        $event->setContent('never render body');
        $event->setTags([
            ['a', $coordinate, 'javascript:untrusted', $pin],
            ['e', str_repeat('d', 64)],
            ['a', '30040:' . str_repeat('b', 64) . ':nested'],
            ['a', $coordinate, '', '<img src=x>'],
            ['a'],
            ['a', 'bad-address'],
            ['a', $coordinate, '', 123],
        ]);
        $identities = new RecordIdentityService();
        $events = $this->createMock(EventRepository::class);
        $events->method('findPublicationIndexTargets')->willReturn(['coordinates' => [], 'eventIds' => []]);
        $pages = new PublicationTocPage(
            new EventWorkspaceSourceAdapter($identities),
            new EventPresentationFactory($identities, new NostrNip19Service()),
            $events,
            $this->createStub(DeletedEventRepository::class),
            $this->createStub(ContentAuthorAccessPolicy::class),
            $this->createStub(\App\Repository\HiddenCoordinateRepository::class),
            new PublicationTocTraversalSigner('test-framework-secret'),
        );
        $page = $pages->fromAuthorizedEvent($event);
        $page['page'] = 2;
        $page['start'] = 51;
        $page['hasPrevious'] = true;
        $page['hasNext'] = true;
        $html = self::getContainer()->get('twig')->render('reader/reading_nook/_publication_toc.html.twig', ['tocPage' => $page]);
        $crawler = new Crawler($html);
        self::assertSame('nook-toc-' . self::ID, $crawler->filter('turbo-frame')->attr('id'));
        self::assertSame('51', $crawler->filter('ol')->attr('start'));
        self::assertSame(['0', '2', '3', '4', '5', '6'], $crawler->filter('li')->extract(['data-source-tag-position']));
        self::assertSame(1, $crawler->filter('li:contains("Index reference: nested")')->count());
        self::assertSame(3, $crawler->filter('li:contains("Section reference: chapter")')->count());
        self::assertSame(1, $crawler->filter('li:contains("Unresolved reference (target not checked)")')->count());
        self::assertSame(1, $crawler->filter('li:contains("Restricted or unavailable index")')->count());
        self::assertSame(1, $crawler->filter('li:contains("Unrecognized version pin (target not checked)")')->count());
        self::assertSame(3, $crawler->filter('li:contains("Invalid publication reference")')->count());
        self::assertStringContainsString('Exact event revision: ' . $pin, $crawler->text());
        self::assertStringContainsString('Invalid version pin: <img src=x>', $crawler->text());
        self::assertSame(0, $crawler->filter('li a, script, img, iframe, li details')->count());
        self::assertStringNotContainsString('never render body', $html);
        self::assertStringNotContainsString('javascript:untrusted', $html);
        self::assertSame('Table of contents pages', $crawler->filter('nav')->attr('aria-label'));
        self::assertStringContainsString('page=1', $crawler->filter('a[rel="prev"]')->attr('href'));
        self::assertStringContainsString('page=3', $crawler->filter('a[rel="next"]')->attr('href'));
    }

    /** @dataProvider expansionFlags */
    public function testResolvedNestedIndexesUseUniqueLazyFramesAndAllowlistedMetadataOnly(bool $enabled): void
    {
        self::bootKernel();
        self::getContainer()->get('twig')->addGlobal('event_workspace_expansion_enabled', $enabled);
        $author = str_repeat('b', 64);
        $coordinate = '30040:' . $author . ':nested';
        $childId = str_repeat('c', 64);
        $child = new Event();
        $child->setId($childId);
        $child->setKind(30040);
        $child->setPubkey($author);
        $child->setDTag('nested');
        $child->setCreatedAt(456);
        $child->setTags([
            ['d', 'nested'],
            ['title', 'Child index title'],
            ['summary', 'Safe child summary'],
            ['image', 'https://private.example/cover.jpg'],
        ]);
        $child->setContent('child body must not render');

        $source = new Event();
        $source->setId(self::ID);
        $source->setPubkey(str_repeat('b', 64));
        $source->setKind(30040);
        $source->setDTag('parent');
        $source->setCreatedAt(123);
        $source->setTags([
            ['d', 'parent'],
            ['a', $coordinate],
            ['a', $coordinate],
        ]);

        $identities = new RecordIdentityService();
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())
            ->method('findPublicationIndexTargets')
            ->with([$coordinate, $coordinate], [])
            ->willReturn(['coordinates' => [$coordinate => $child], 'eventIds' => []]);
        $access = $this->createMock(ContentAuthorAccessPolicy::class);
        $access->expects(self::once())->method('suppressedPubkeys')->with([$author])->willReturn([]);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::once())->method('findSuppressedEventIds')->with([$child])->willReturn([]);
        $pages = new PublicationTocPage(
            new EventWorkspaceSourceAdapter($identities),
            new EventPresentationFactory($identities, new NostrNip19Service()),
            $events,
            $deletions,
            $access,
            $this->createStub(\App\Repository\HiddenCoordinateRepository::class),
            new PublicationTocTraversalSigner('test-framework-secret'),
        );

        $html = self::getContainer()->get('twig')->render(
            'reader/reading_nook/_publication_toc.html.twig',
            ['tocPage' => $pages->fromAuthorizedEvent($source)],
        );
        $crawler = new Crawler($html);

        self::assertSame($enabled ? 3 : 1, $crawler->filter('turbo-frame')->count());
        self::assertCount($enabled ? 3 : 1, array_unique($crawler->filter('turbo-frame')->extract(['id'])));
        self::assertSame($enabled ? 2 : 0, $crawler->filter('turbo-frame[loading="lazy"]')->count());
        self::assertSame($enabled ? 2 : 0, $crawler->filter('details.event-toc-disclosure')->count());
        foreach ($crawler->filter('turbo-frame[loading="lazy"]') as $frame) {
            self::assertInstanceOf(\DOMElement::class, $frame);
            $frameCrawler = new Crawler($frame);
            self::assertSame($frame->getAttribute('src'), $frameCrawler->filter('a')->attr('href'));
            self::assertStringContainsString('signature=', $frame->getAttribute('src'));
        }
        self::assertSame(2, $crawler->filter('strong:contains("Child index title")')->count());
        self::assertSame(2, $crawler->filter('p:contains("Safe child summary")')->count());
        self::assertStringNotContainsString('child body must not render', $html);
        self::assertStringNotContainsString('private.example', $html);
        self::assertSame(0, $crawler->filter('img, video, audio, a[href*="/event/"]')->count());
    }

    public function testEmptyTocPageKeepsSafePaginationAtMaximumPage(): void
    {
        self::bootKernel();
        $html = self::getContainer()->get('twig')->render('reader/reading_nook/_publication_toc.html.twig', [
            'tocPage' => [
                'eventId' => self::ID, 'page' => PHP_INT_MAX, 'start' => 1,
                'entries' => [], 'hasPrevious' => true, 'hasNext' => false,
            ],
        ]);
        $crawler = new Crawler($html);
        self::assertSame(0, $crawler->filter('li, a[rel="next"]')->count());
        self::assertStringContainsString('No table of contents entries on this page.', $crawler->text());
        self::assertStringContainsString('page=' . (PHP_INT_MAX - 1), $crawler->filter('a[rel="prev"]')->attr('href'));
    }

    /** @dataProvider expansionFlags */
    public function testInventoryKeepsTitleOpenManageAndIndependentActionMenu(bool $enabled): void
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $twig->addGlobal('event_workspace_expansion_enabled', $enabled);
        // Isolate the inventory from the unrelated site shell and icon bundle.
        $twig->setLoader(new ChainLoader([
            new ArrayLoader(['reading-nook-layout.html.twig' => '{% block body %}{% endblock %}']),
            $twig->getLoader(),
        ]));
        $twig->addFunction(new TwigFunction('ux_icon', static fn (): string => ''));
        $item = [
            'eventId' => self::ID, 'title' => 'My pack', 'url' => '/open', 'manageUrl' => '/manage',
            'kind' => 39089, 'section' => 'follow_packs', 'createdAt' => new \DateTimeImmutable(),
            'updatedAt' => null, 'summary' => '', 'tags' => [], 'meta' => ['memberCount' => 2],
        ];
        $index = array_replace($item, ['eventId' => str_repeat('b', 64), 'kind' => 30040, 'section' => 'reading_lists', 'title' => 'My index']);
        $other = array_replace($item, ['kind' => 3, 'title' => 'Not expanded']);
        $curation = array_replace($index, ['kind' => 30004]);
        $subscription = array_replace($index, ['section' => 'subscriptions']);
        $bookmarks = array_replace($item, ['eventId' => str_repeat('c', 64), 'kind' => 10003, 'section' => 'bookmarks', 'title' => 'My bookmarks']);
        $bookmarkSet = array_replace($bookmarks, ['eventId' => str_repeat('d', 64), 'kind' => 30003, 'title' => 'Saved articles']);
        $crawler = new Crawler($twig->render('reader/reading_nook/index.html.twig', [
            'filters' => ['q' => '', 'section' => 'all', 'timespan' => 'all', 'tag' => ''],
            'allTags' => [], 'totalCount' => 2, 'filteredCount' => 2, 'sectionKeys' => [],
            'sectionLabels' => ['bookmarks' => 'reading_nook.section.bookmarks', 'follow_packs' => 'reading_nook.section.follow_packs', 'reading_lists' => 'reading_nook.section.reading_lists', 'subscriptions' => 'reading_nook.section.subscriptions'],
            'sectionCounts' => [], 'timespanOptions' => [], 'items' => [$item, $index, $other, $curation, $subscription, $bookmarks, $bookmarkSet],
        ]));

        $row = $crawler->filter('article')->first();
        self::assertSame('/open', $row->filter('h2 a')->attr('href'));
        self::assertSame(['Open', 'Manage'], $row->filter('.reading-nook-menu__panel a')->each(static fn (Crawler $a) => trim($a->text())));
        self::assertSame(['/open', '/manage'], $row->filter('.reading-nook-menu__panel a')->extract(['href']));
        self::assertSame('Actions for My pack', $row->filter('.reading-nook-menu > summary')->attr('aria-label'));
        self::assertSame(1, $row->filter('details.reading-nook-menu[name="reading-nook-actions"]')->count());
        self::assertSame($enabled ? 1 : 0, $crawler->filter('.bookshelf-directory-disclosure')->count());
        if (!$enabled) {
            self::assertSame(0, $crawler->filter('turbo-frame, .event-member-disclosure, .event-toc-disclosure, .event-bookmark-disclosure')->count());
            self::assertCount(7, $crawler->filter('.reading-nook-menu'));

            return;
        }
        self::assertSame('/bookshelf/my-books/references', $crawler->filter('#bookshelf-directory-references')->attr('src'));
        self::assertCount(0, $crawler->filter('article .bookshelf-directory-disclosure, .reading-nook-menu .bookshelf-directory-disclosure'));
        self::assertSame(1, $row->filter('details.event-member-disclosure')->count());
        self::assertSame(0, $row->filter('.reading-nook-menu .event-member-disclosure, h2 .event-member-disclosure')->count());
        self::assertSame(0, $crawler->filter('article')->last()->filter('.event-member-disclosure')->count());
        $indexRow = $crawler->filter('article')->eq(1);
        self::assertSame('/open', $indexRow->filter('h2 a')->attr('href'));
        self::assertSame(['/open', '/manage'], $indexRow->filter('.reading-nook-menu__panel a')->extract(['href']));
        self::assertSame('Actions for My index', $indexRow->filter('.reading-nook-menu > summary')->attr('aria-label'));
        self::assertSame(1, $indexRow->filter('details.reading-nook-menu[name="reading-nook-actions"]')->count());
        self::assertSame(1, $indexRow->filter('details.event-toc-disclosure')->count());
        self::assertSame(0, $indexRow->filter('.reading-nook-menu .event-toc-disclosure, h2 .event-toc-disclosure')->count());
        self::assertSame(1, $crawler->filter('.event-toc-disclosure')->count());
        self::assertSame(2, $crawler->filter('.event-bookmark-disclosure')->count());
        self::assertSame(0, $crawler->filter('article')->eq(2)->filter('.event-bookmark-disclosure')->count());
        $ids = $crawler->filter('turbo-frame')->extract(['id']);
        self::assertCount(5, $ids);
        self::assertCount(count($ids), array_unique($ids));
    }

    /** @return iterable<string, array{bool}> */
    public static function expansionFlags(): iterable
    {
        yield 'enabled' => [true];
        yield 'rollback' => [false];
    }

    public function testAllWrappersAndNestedShellHonorRollbackAndRetainAttributes(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        $names = ['EventMemberDisclosure', 'EventBookmarkDisclosure', 'EventTocDisclosure', 'BookshelfDirectoryDisclosure', 'LazyDisclosure'];
        foreach ([true, false] as $enabled) {
            $twig->addGlobal('event_workspace_expansion_enabled', $enabled);
            foreach ($names as $name) {
                $props = ['url' => '/exact?eventId=pinned&page=2&signature=a%2Bb', 'data-marker' => 'kept'];
                if (in_array($name, ['EventMemberDisclosure', 'EventBookmarkDisclosure', 'EventTocDisclosure'], true)) {
                    $props += ['eventId' => self::ID, 'title' => '<unsafe>'];
                } elseif ($name === 'LazyDisclosure') {
                    $props += ['frameId' => 'nook-toc-nested-occurrence', 'summary' => '<unsafe>', 'loadLabel' => 'Load'];
                }
                $crawler = $this->renderTwigComponent('Molecules:' . $name, $props)->crawler();
                self::assertCount($enabled ? 1 : 0, $crawler->filter('details'));
                self::assertCount(0, $crawler->filter('details[open], details[name], unsafe'));
                if ($enabled) {
                    self::assertSame('kept', $crawler->filter('details')->attr('data-marker'));
                    self::assertSame($props['url'], $crawler->filter('turbo-frame')->attr('src'));
                    self::assertSame($props['url'], $crawler->filter('a')->attr('href'));
                    self::assertSame('lazy', $crawler->filter('turbo-frame')->attr('loading'));
                } else {
                    self::assertCount(0, $crawler->filter('a, summary, turbo-frame'));
                }
            }
        }
    }
}

final class EventMemberDisclosureTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new TwigComponentBundle();
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true, 'secret' => 'member-test',
            'property_info' => ['with_constructor_extractor' => true],
        ]);
        $container->import($this->getProjectDir() . '/config/packages/translation.yaml');
        $container->extension('twig', [
            'default_path' => '%kernel.project_dir%/templates', 'strict_variables' => true,
            'globals' => ['event_workspace_expansion_enabled' => true],
        ]);
        $container->extension('twig_component', [
            'anonymous_template_directory' => 'components/',
            'defaults' => ['App\\Twig\\Components\\' => 'components/'],
        ]);
        foreach ([LazyDisclosure::class, BookshelfDirectoryDisclosure::class, EventBookmarkDisclosure::class, EventMemberDisclosure::class, EventTocDisclosure::class, PageHeading::class] as $component) {
            $container->services()->set($component)->autowire()->autoconfigure();
        }
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('reading_nook', '/reading-nook');
        $routes->add('my_content', '/my-content');
        $routes->add('my_content_preview', '/my-content/preview/{eventId}');
        $routes->add('editor-create', '/editor/create');
        $routes->add('editor-edit-slug', '/editor/{slug}');
        $routes->add('editor-edit-slug-draft', '/editor/draft/{slug}');
        $routes->add('author-article-slug', '/author/{npub}/{slug}');
        $routes->add('api_settings_event_publish', '/api/settings/event/publish');
        $routes->add('bookshelf', '/bookshelf');
        $routes->add('bookshelf_read', '/bookshelf/{id}');
        $routes->add('bookshelf_read_native_fallback', '/bookshelf/{id}');
        $routes->add('nevent', '/e/{nevent}');
        $routes->add('magazine-chapter', '/magazine/{mag}/chapter/{slug}');
        $routes->add('bookshelf_directory_publish', '/api/bookshelf/directory');
        $routes->add('bookshelf_directory_references', '/bookshelf/my-books/references');
        $routes->add('reading_nook_follow_pack_members', '/reading-nook/follow-packs/{eventId}/members');
        $routes->add('reading_nook_bookmark_references', '/reading-nook/bookmarks/{eventId}/references');
        $routes->add('reading_nook_publication_toc', '/reading-nook/publication-indexes/{eventId}/toc');
        $routes->add('reading_nook_nested_publication_toc', '/reading-nook/publication-indexes/{eventId}/nested-toc');
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/newsroom-member-test-' . getmypid() . '/' . $this->getEnvironment();
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/newsroom-member-test-' . getmypid() . '/log';
    }
}
