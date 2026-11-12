<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components\Molecules;

use App\Twig\Components\Atoms\PageHeading;
use App\Twig\Components\Molecules\EventMemberDisclosure;
use App\Twig\Components\Molecules\EventTocDisclosure;
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

    public function testResolvedNestedIndexesUseUniqueLazyFramesAndAllowlistedMetadataOnly(): void
    {
        self::bootKernel();
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

        self::assertSame(3, $crawler->filter('turbo-frame')->count());
        self::assertCount(3, array_unique($crawler->filter('turbo-frame')->extract(['id'])));
        self::assertSame(2, $crawler->filter('turbo-frame[loading="lazy"]')->count());
        self::assertSame(2, $crawler->filter('details.event-toc-disclosure')->count());
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

    public function testInventoryKeepsTitleOpenManageAndIndependentActionMenu(): void
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
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
        $crawler = new Crawler($twig->render('reader/reading_nook/index.html.twig', [
            'filters' => ['q' => '', 'section' => 'all', 'timespan' => 'all', 'tag' => ''],
            'allTags' => [], 'totalCount' => 2, 'filteredCount' => 2, 'sectionKeys' => [],
            'sectionLabels' => ['follow_packs' => 'reading_nook.section.follow_packs', 'reading_lists' => 'reading_nook.section.reading_lists', 'subscriptions' => 'reading_nook.section.subscriptions'],
            'sectionCounts' => [], 'timespanOptions' => [], 'items' => [$item, $index, $other, $curation, $subscription],
        ]));

        $row = $crawler->filter('article')->first();
        self::assertSame('/open', $row->filter('h2 a')->attr('href'));
        self::assertSame(['Open', 'Manage'], $row->filter('.reading-nook-menu__panel a')->each(static fn (Crawler $a) => trim($a->text())));
        self::assertSame(['/open', '/manage'], $row->filter('.reading-nook-menu__panel a')->extract(['href']));
        self::assertSame('Actions for My pack', $row->filter('.reading-nook-menu > summary')->attr('aria-label'));
        self::assertSame(1, $row->filter('details.reading-nook-menu[name="reading-nook-actions"]')->count());
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
        self::assertSame(2, count(array_unique($crawler->filter('turbo-frame')->extract(['id']))));
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
        $container->extension('twig', ['default_path' => '%kernel.project_dir%/templates', 'strict_variables' => true]);
        $container->extension('twig_component', [
            'anonymous_template_directory' => 'components/',
            'defaults' => ['App\\Twig\\Components\\' => 'components/'],
        ]);
        foreach ([EventMemberDisclosure::class, EventTocDisclosure::class, PageHeading::class] as $component) {
            $container->services()->set($component)->autowire()->autoconfigure();
        }
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('reading_nook', '/reading-nook');
        $routes->add('reading_nook_follow_pack_members', '/reading-nook/follow-packs/{eventId}/members');
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
