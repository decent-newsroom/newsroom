<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Dto\EventWorkspace\EventPresentation;
use App\Dto\EventWorkspace\EventReference;
use App\Dto\UserMetadata;
use App\Service\Cache\RedisCacheService;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\ProfileUpdateDispatcher;
use App\Twig\Components\Molecules\EventPreview;
use App\Twig\Components\Molecules\UserFromNpub;
use App\Twig\Filters;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;

final class BookshelfIndexTemplateTest extends KernelTestCase
{
    private const EVENT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const AUTHOR = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $cache = $this->createMock(RedisCacheService::class);
        $cache->expects(self::never())->method('getMetadata');
        $dispatcher = $this->createMock(ProfileUpdateDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');
        self::getContainer()->set(RedisCacheService::class, $cache);
        self::getContainer()->set(ProfileUpdateDispatcher::class, $dispatcher);

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $twig->addGlobal('app', ['user' => null]);
        $twig->setLoader(new ChainLoader([
            new ArrayLoader([
                '@Bookshelf/bookshelf-layout.html.twig' => '<!DOCTYPE html><html><head><title>{% block title %}{% endblock %}</title></head><body>{% block body %}{% endblock %}</body></html>',
            ]),
            $twig->getLoader(),
        ]));
    }

    public function testPublicationTitleAndLocallyResolvedAuthorAreLinkedAndEscaped(): void
    {
        $title = 'Collected <script>Stories</script> & essays';
        $presentation = $this->presentation([
            ['d', 'stories'], ['title', $title], ['summary', 'A collection'],
        ]);
        $html = $this->renderIndex($presentation, new UserMetadata(name: 'jane', displayName: 'Jane <Doe>'), true);
        $crawler = new Crawler($html);
        $npub = $presentation->authorNpub;

        self::assertNotNull($npub);
        self::assertCount(1, $crawler->filter('h1'));
        self::assertSame($title, $crawler->filter('h1')->text());
        self::assertSame($title . ' | ' . $this->trans('bookshelf.heading'), $crawler->filter('title')->text());
        self::assertSame('/p/' . $npub, $crawler->filter('.bookshelf-publication__publisher a')->attr('href'));
        self::assertSame('_top', $crawler->filter('.bookshelf-publication__publisher a')->attr('data-turbo-frame'));
        self::assertSame('Jane <Doe>', $crawler->filter('.bookshelf-publication__publisher a')->text());
        self::assertCount(0, $crawler->filter('.event-preview__title, .event-preview__byline, .event-preview__author, script, doe'));
        self::assertSame('A collection', $crawler->filter('.event-preview__description')->text());
        self::assertSame('/bookshelf/' . self::EVENT_ID, $crawler->selectLink('Read this book')->attr('href'));
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('secret body', $html);
    }

    /**
     * @dataProvider titleCases
     * @param list<list<string>> $tags
     */
    public function testMissingOrPartialTitlesUseTheExistingTagFallbacks(array $tags, string $expected): void
    {
        $presentation = $this->presentation($tags);
        $crawler = new Crawler($this->renderIndex($presentation));
        $npub = $presentation->authorNpub;

        self::assertNotNull($npub);
        self::assertSame($expected, $crawler->filter('h1')->text());
        self::assertSame($expected . ' | ' . $this->trans('bookshelf.heading'), $crawler->filter('title')->text());
        self::assertSame('/p/' . $npub, $crawler->filter('.bookshelf-publication__publisher a')->attr('href'));
        self::assertSame(substr($npub, 0, 8) . '…' . substr($npub, -4), $crawler->filter('.bookshelf-publication__publisher a')->text());
        self::assertCount(0, $crawler->filter('.event-preview__title, .event-preview__byline, .event-preview__author'));
    }

    /** @return iterable<string, array{list<list<string>>, string}> */
    public static function titleCases(): iterable
    {
        yield 'no title' => [[], 'Untitled event'];
        yield 'name tag' => [[['name', 'Collection name']], 'Collection name'];
        yield 'identifier tag' => [[['d', 'collection-id']], 'collection-id'];
        yield 'literal zero' => [[['title', '0']], '0'];
    }

    public function testSensitiveTitleAndSummaryStayHiddenInHeadingAndDocumentTitle(): void
    {
        $presentation = $this->presentation([
            ['title', 'secret title'], ['summary', 'secret summary'], ['content-warning', 'secret warning'],
        ]);
        $html = $this->renderIndex($presentation, new UserMetadata(displayName: 'Jane'));
        $crawler = new Crawler($html);

        self::assertSame($this->trans('event_workspace.preview.sensitive'), $crawler->filter('h1')->text());
        self::assertSame(
            $this->trans('event_workspace.preview.sensitive') . ' | ' . $this->trans('bookshelf.heading'),
            $crawler->filter('title')->text(),
        );
        self::assertSame('Jane', $crawler->filter('.bookshelf-publication__publisher a')->text());
        self::assertCount(0, $crawler->filter('.event-preview__title, .event-preview__sensitive, .event-preview__description, .event-preview__author'));
        foreach (['secret title', 'secret summary', 'secret warning', 'secret body'] as $secret) {
            self::assertStringNotContainsString($secret, $html);
        }
    }

    public function testMissingAuthorIsShownAsUnknownWithoutAnInvalidLink(): void
    {
        $presentation = $this->presentation([['title', 'Index']], '');
        $crawler = new Crawler($this->renderIndex($presentation));

        self::assertSame($this->trans('text.byline') . ' ' . $this->trans('text.unknown'), $crawler->filter('.bookshelf-publication__publisher')->text());
        self::assertCount(0, $crawler->filter('.bookshelf-publication__publisher a'));
    }

    public function testContainingIndexLinksAreExactEscapedAndNavigateTheWholePage(): void
    {
        $parentId = str_repeat('c', 64);
        $otherId = str_repeat('d', 64);
        $html = $this->renderIndex($this->presentation([['title', 'Child index']]), containingIndexes: [
            ['title' => '<script>Parent & first</script>', 'eventId' => $parentId],
            ['title' => 'Another parent', 'eventId' => $otherId],
        ]);
        $crawler = new Crawler($html);
        $links = $crawler->filter('[aria-labelledby="publication-containing-indexes"] li a');

        self::assertSame($this->trans('bookshelf.contained_in_index'), $crawler->filter('#publication-containing-indexes')->text());
        self::assertCount(2, $links);
        self::assertSame('<script>Parent & first</script>', $links->first()->text());
        self::assertSame('/bookshelf/index/' . $parentId, $links->first()->attr('href'));
        self::assertSame('/bookshelf/index/' . $otherId, $links->last()->attr('href'));
        self::assertSame('_top', $links->first()->attr('data-turbo-frame'));
        self::assertSame('_top', $links->last()->attr('data-turbo-frame'));
        self::assertCount(0, $crawler->filter('script'));
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testContainingIndexSectionIsHiddenWhenThereAreNoParents(): void
    {
        $crawler = new Crawler($this->renderIndex($this->presentation([['title', 'Child index']])));

        self::assertCount(0, $crawler->filter('#publication-containing-indexes'));
    }

    public function testRootTocIsAnIndependentFrameWithoutHydratedRows(): void
    {
        $crawler = new Crawler($this->renderIndex($this->presentation([
            ['a', '30040:' . self::AUTHOR . ':secret-child'],
        ])));
        $frame = $crawler->filter('turbo-frame#nook-toc-' . self::EVENT_ID);
        self::assertCount(1, $frame);
        self::assertSame('/bookshelf/index/' . self::EVENT_ID . '/contents?page=1', $frame->attr('src'));
        self::assertCount(0, $frame->filter('.event-toc-list li'));
        self::assertStringNotContainsString('secret-child', $frame->text());
    }

    public function testArticleLeavesUseTheTranslatedArticlesHeading(): void
    {
        $presentation = $this->presentation([
            ['a', '30023:' . self::AUTHOR . ':first'],
            ['a', '30023:' . self::AUTHOR . ':second'],
        ]);
        $crawler = new Crawler($this->renderIndex(
            $presentation,
            tocHeading: 'bookshelf.publication.articles',
        ));

        self::assertSame($this->trans('bookshelf.publication.articles'), $crawler->filter('#bookshelf-index-contents')->text());
        self::assertCount(0, $crawler->filter('.bookshelf-publication__chapters h3'));
    }

    public function testFeaturedArticlePrecedesTheIndexListAndKeepsItsRawPin(): void
    {
        $identifier = 'headline<script>alert(1)</script>';
        $presentation = $this->presentation([
            ['d', 'index'], ['summary', 'Public summary'],
            ['a', '30040:' . self::AUTHOR . ':section'],
            ['a', '30023:' . self::AUTHOR . ':' . $identifier, '', 'bad-pin'],
        ]);
        $tocPage = [
            'eventId' => self::EVENT_ID, 'page' => 1, 'start' => 1,
            'featuredEntry' => $this->tocEntry($presentation->references[1], 'bad-pin'),
            'entries' => [$this->tocEntry($presentation->references[0])],
            'hasPrevious' => false, 'hasNext' => false,
        ];
        $html = $this->renderIndex($presentation, tocHeading: 'bookshelf.publication.contents', tocPage: $tocPage);
        $crawler = new Crawler($html);
        $frame = $crawler->filter('#nook-toc-' . self::EVENT_ID);

        self::assertSame($this->trans('bookshelf.publication.contents'), (new Crawler($this->renderIndex(
            $presentation, tocHeading: 'bookshelf.publication.contents',
        )))->filter('#bookshelf-index-contents')->text());
        self::assertSame([
            $this->trans('bookshelf.publication.featured_article'),
            $this->trans('bookshelf.publication.indexes'),
        ], $frame->filter('h3')->each(static fn (Crawler $heading): string => $heading->text()));
        self::assertSame(['3', '2'], $frame->filter('li')->each(static fn (Crawler $item): ?string => $item->attr('data-source-tag-position')));
        self::assertSame(
            self::getContainer()->get('translator')->trans('bookshelf.publication.article_reference', ['%identifier%' => $identifier]),
            $frame->filter('section li strong')->text(),
        );
        self::assertStringContainsString('bad-pin', $frame->filter('section')->text());
        self::assertCount(0, $crawler->filter('script'));
        self::assertStringNotContainsString('<script>', $html);
    }

    public function testNestedFeaturedSectionsUseTheOccurrenceSpecificFrameId(): void
    {
        $presentation = $this->presentation([
            ['a', '30023:' . self::AUTHOR . ':featured'],
            ['a', '30040:' . self::AUTHOR . ':section'],
        ]);
        $occurrence = str_repeat('c', 64);
        $frameId = 'nook-toc-nested-' . $occurrence;
        $html = self::getContainer()->get('twig')->render('reader/reading_nook/_publication_toc.html.twig', [
            'tocPage' => [
                'eventId' => self::EVENT_ID, 'frameId' => $frameId, 'page' => 1, 'start' => 1,
                'occurrenceId' => $occurrence, 'ancestorPath' => [str_repeat('d', 64)],
                'coordinate' => '30040:' . self::AUTHOR . ':index',
                'pageSignatures' => ['previous' => null, 'next' => null],
                'featuredEntry' => $this->tocEntry($presentation->references[0]),
                'entries' => [$this->tocEntry($presentation->references[1])],
                'hasPrevious' => false, 'hasNext' => false,
            ],
        ]);
        $crawler = new Crawler($html);

        self::assertCount(1, $crawler->filter('turbo-frame#' . $frameId));
        self::assertSame(
            $this->trans('bookshelf.publication.featured_article'),
            $crawler->filter('#' . $frameId . '-featured')->text(),
        );
        self::assertSame(['0', '1'], $crawler->filter('li')->each(static fn (Crawler $item): ?string => $item->attr('data-source-tag-position')));
    }

    public function testKnownBookshelfTargetsShowMetadataWithoutReferenceIdentifiers(): void
    {
        $presentation = $this->presentation([
            ['a', '30023:' . self::AUTHOR . ':article-slug', '', str_repeat('c', 64)],
            ['a', '30040:' . self::AUTHOR . ':index-slug', '', str_repeat('d', 64)],
            ['a', '30040:' . self::AUTHOR . ':missing'],
        ]);
        $article = $this->tocEntry($presentation->references[0]);
        $article['targetResolution'] = 'available';
        $article['target'] = [
            'eventId' => str_repeat('c', 64), 'title' => 'Article <title>',
            'summary' => 'Article & summary', 'sensitive' => false,
        ];
        $index = $this->tocEntry($presentation->references[1]);
        $index['targetResolution'] = 'available';
        $index['target'] = [
            'eventId' => str_repeat('d', 64), 'title' => 'Index title',
            'summary' => 'Index summary', 'sensitive' => false,
        ];
        $page = [
            'eventId' => self::EVENT_ID, 'page' => 1, 'start' => 1, 'featuredEntry' => $article,
            'entries' => [$index, $this->tocEntry($presentation->references[2])],
            'hasPrevious' => false, 'hasNext' => false,
        ];
        $html = $this->renderIndex($presentation, tocPage: $page);
        $crawler = new Crawler($html);
        $rows = $crawler->filter('.event-toc-list li');
        self::assertSame('Article <title>', $rows->eq(0)->filter('strong')->text());
        self::assertSame('Article & summary', $rows->eq(0)->filter('p')->text());
        self::assertSame('Index title', $rows->eq(1)->filter('strong')->text());
        self::assertSame('Index summary', $rows->eq(1)->filter('p')->text());
        self::assertSame('/bookshelf/index/' . str_repeat('d', 64), $rows->eq(1)->filter('a')->attr('href'));
        self::assertCount(0, $rows->eq(0)->filter('a, code'));
        self::assertCount(0, $rows->eq(1)->filter('code'));
        foreach (['article-slug', 'index-slug', str_repeat('c', 64), str_repeat('d', 64), 'reference'] as $identifier) {
            self::assertStringNotContainsString($identifier, strtolower($rows->eq(0)->text() . $rows->eq(1)->text()));
        }
        self::assertStringContainsString('missing', $rows->eq(2)->text());
        self::assertCount(1, $rows->eq(2)->filter('code'));
        self::assertStringNotContainsString('<title>', $rows->eq(0)->html());
    }

    public function testKnownSensitiveAndUntitledIndexesDoNotFallBackToIdentifiers(): void
    {
        $presentation = $this->presentation([
            ['a', '30040:' . self::AUTHOR . ':sensitive'],
            ['a', '30040:' . self::AUTHOR . ':untitled'],
        ]);
        $sensitive = $this->tocEntry($presentation->references[0]);
        $sensitive['targetResolution'] = 'available';
        $sensitive['target'] = ['eventId' => str_repeat('c', 64), 'title' => null, 'summary' => null, 'sensitive' => true];
        $untitled = $this->tocEntry($presentation->references[1]);
        $untitled['targetResolution'] = 'available';
        $untitled['target'] = ['eventId' => str_repeat('d', 64), 'title' => null, 'summary' => null, 'sensitive' => false];
        $crawler = new Crawler($this->renderIndex($presentation, tocPage: [
            'eventId' => self::EVENT_ID, 'page' => 1, 'start' => 1, 'featuredEntry' => null,
            'entries' => [$sensitive, $untitled], 'hasPrevious' => false, 'hasNext' => false,
        ]));
        $rows = $crawler->filter('.event-toc-list li');
        self::assertSame($this->trans('reading_nook.toc.sensitive'), $rows->eq(0)->filter('strong')->text());
        self::assertSame($this->trans('event_workspace.preview.untitled'), $rows->eq(1)->filter('strong')->text());
        self::assertCount(0, $rows->filter('code'));
    }

    public function testPublicationContentsLabelsExistInEveryEnabledLocale(): void
    {
        $translator = self::getContainer()->get('translator');
        $locales = self::getContainer()->getParameter('kernel.enabled_locales');
        foreach ($locales as $locale) {
            $translator->setLocale($locale);
            foreach (['articles', 'chapters', 'contents', 'featured_article', 'article_reference', 'indexes'] as $label) {
                $key = 'bookshelf.publication.' . $label;
                self::assertNotSame($key, $translator->trans($key), $locale . ': ' . $key);
                if (in_array($label, ['featured_article', 'article_reference', 'indexes'], true)) {
                    self::assertTrue($translator->getCatalogue($locale)->defines($key), $locale . ': ' . $key);
                }
            }
        }
        $translator->setLocale('en');
    }

    /** @param list<list<string>> $tags */
    private function presentation(array $tags, string $author = self::AUTHOR): EventPresentation
    {
        $factory = new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service());

        return $factory->fromAuthorizedFields(self::EVENT_ID, 30040, $author, 123, $tags, 'secret body');
    }

    /** @return array<string, mixed> */
    private function tocEntry(EventReference $reference, ?string $rawPin = null): array
    {
        return [
            'reference' => $reference,
            'rawVersionPin' => $rawPin,
            'invalidVersionPin' => $rawPin !== null && $rawPin !== '' && $reference->versionEventId === null,
            'targetResolution' => null,
            'target' => null,
            'cycle' => false,
            'depthLimited' => false,
            'nestedOccurrenceId' => null,
        ];
    }

    /**
     * @param list<array{title: string, eventId: string}> $containingIndexes
     * @param array<string, mixed>|null $tocPage
     */
    private function renderIndex(
        EventPresentation $presentation,
        ?UserMetadata $author = null,
        bool $isBook = false,
        string $tocHeading = 'bookshelf.publication.chapters',
        array $containingIndexes = [],
        ?array $tocPage = null,
    ): string {
        if ($tocPage !== null) {
            return self::getContainer()->get('twig')->render('reader/reading_nook/_publication_toc.html.twig', [
                'tocPage' => $tocPage,
                'tocBookshelfLinks' => true,
            ]);
        }
        return self::getContainer()->get('twig')->render('bookshelf/index.html.twig', [
            'presentation' => $presentation,
            'authorMetadata' => ($author ?? new UserMetadata())->toStdClass(),
            'isBook' => $isBook,
            'tocHeading' => $tocHeading,
            'containingIndexes' => $containingIndexes,
            'tocPageNumber' => 1,
        ]);
    }

    private function trans(string $key): string
    {
        return self::getContainer()->get('translator')->trans($key);
    }

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new BookshelfIndexTemplateTestKernel('test', true);
    }
}

final class BookshelfIndexTemplateTestKernel extends Kernel
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
            'test' => true, 'secret' => 'bookshelf-index-template-test',
            'property_info' => ['with_constructor_extractor' => true],
        ]);
        $container->import($this->getProjectDir() . '/config/packages/translation.yaml');
        $container->extension('twig', [
            'default_path' => '%kernel.project_dir%/templates', 'strict_variables' => true,
        ]);
        $container->extension('twig_component', [
            'anonymous_template_directory' => 'components/',
            'defaults' => ['App\\Twig\\Components\\' => 'components/'],
        ]);
        $services = $container->services();
        $services->set(RedisCacheService::class)->synthetic()->public();
        $services->set(ProfileUpdateDispatcher::class)->synthetic()->public();
        $services->set(EventPreview::class)->autowire()->autoconfigure();
        $services->set(UserFromNpub::class)->autowire()->autoconfigure();
        $services->set(Filters::class)->autoconfigure();
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('bookshelf', '/bookshelf');
        $routes->add('bookshelf_index', '/bookshelf/index/{eventId}');
        $routes->add('bookshelf_index_contents', '/bookshelf/index/{eventId}/contents');
        $routes->add('bookshelf_read_native_fallback', '/bookshelf/{id}');
        $routes->add('author-profile', '/p/{npub}');
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/newsroom-bookshelf-index-template-' . getmypid() . '/' . $this->getEnvironment();
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/newsroom-bookshelf-index-template-' . getmypid() . '/log';
    }
}
