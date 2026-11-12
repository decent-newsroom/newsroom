<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components\Molecules;

use App\Twig\Components\Atoms\PageHeading;
use App\Twig\Components\Molecules\EventMemberDisclosure;
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
        $other = array_replace($item, ['kind' => 3, 'title' => 'Not expanded']);
        $crawler = new Crawler($twig->render('reader/reading_nook/index.html.twig', [
            'filters' => ['q' => '', 'section' => 'all', 'timespan' => 'all', 'tag' => ''],
            'allTags' => [], 'totalCount' => 2, 'filteredCount' => 2, 'sectionKeys' => [],
            'sectionLabels' => ['follow_packs' => 'reading_nook.section.follow_packs'],
            'sectionCounts' => [], 'timespanOptions' => [], 'items' => [$item, $other],
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
        foreach ([EventMemberDisclosure::class, PageHeading::class] as $component) {
            $container->services()->set($component)->autowire()->autoconfigure();
        }
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('reading_nook', '/reading-nook');
        $routes->add('reading_nook_follow_pack_members', '/reading-nook/follow-packs/{eventId}/members');
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
