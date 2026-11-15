<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use App\Twig\Components\Molecules\EventPreview;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Twig\Environment;
use Twig\TwigFilter;

final class WorkspaceNoteBodyTemplateTest extends KernelTestCase
{
    public function testFullBodyAndExistingLinksRemainAlongsideSharedPreview(): void
    {
        $presentation = (new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()))
            ->fromAuthorizedFields(
                str_repeat('a', 64), 1, str_repeat('b', 64), 123,
                [
                    ['title', 'Note title'], ['summary', 'Summary'], ['t', 'news'],
                    ['r', 'https://example.test/source'], ['e', str_repeat('c', 64)],
                ],
                'Full & untrusted body',
            );
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $twig->addFilter(new TwigFilter('resolve_nostr_embeds', static fn (string $content): string => $content));
        $twig->addFilter(new TwigFilter('toNpub', static fn (string $value): string => $value));
        $twig->addFilter(new TwigFilter('nEncode', static fn (string $value): string => $value));
        $html = $twig->render('event/_workspace_note_body.html.twig', ['presentation' => $presentation]);
        $crawler = new Crawler($html);

        self::assertSame('Note title', $crawler->filter('.event-preview__title')->text());
        self::assertSame('Summary', $crawler->filter('.event-preview__description')->text());
        self::assertSame('Full & untrusted body', $crawler->filter('.generic-event-content')->text());
        self::assertStringContainsString('Full &amp; untrusted body', $html);
        self::assertSame('/topics/news', $crawler->filter('.event-meta-topics a')->attr('href'));
        self::assertSame('https://example.test/source', $crawler->filter('.event-meta-sources a')->attr('href'));
        self::assertSame('/e/' . str_repeat('c', 64), $crawler->filter('.event-meta-references a')->attr('href'));
    }

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new WorkspaceNoteTemplateKernel($options['environment'] ?? 'test', $options['debug'] ?? true);
    }
}

#[AsTwigComponent('Atoms:Content')]
final class WorkspaceNoteTestContent
{
    public string $parsed = '';

    public function mount(string $content): void
    {
        $this->parsed = htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

final class WorkspaceNoteTemplateKernel extends Kernel
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
            'test' => true,
            'secret' => 'workspace-note',
            'property_info' => ['with_constructor_extractor' => true],
        ]);
        $container->import($this->getProjectDir() . '/config/packages/translation.yaml');
        $container->extension('twig', ['default_path' => '%kernel.project_dir%/templates', 'strict_variables' => true]);
        $container->extension('twig_component', [
            'anonymous_template_directory' => 'components/',
            'defaults' => ['App\\Twig\\Components\\' => 'components/'],
        ]);
        $container->services()->set(EventPreview::class)->autowire()->autoconfigure();
        $container->services()->set(WorkspaceNoteTestContent::class)->autoconfigure();
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('topics', '/topics/{tag}');
        $routes->add('nevent', '/e/{nevent}');
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/newsroom-workspace-note-' . getmypid() . '/' . $this->getEnvironment();
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/newsroom-workspace-note-' . getmypid() . '/log';
    }
}
