<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Entity\Event;
use App\Repository\ArticleRepository;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\AuthoredArticlePreview;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrEventVerifier;
use App\Service\Nostr\NostrIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Nostr\NostrSigner;
use App\Service\Reader\ArticleAccessService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use App\Twig\Components\Molecules\NostrEmbed;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class HighlightReferenceTemplateTest extends KernelTestCase
{
    private const AUTHOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const IDENTIFIER = 'source:edition';
    private const COORDINATE = '30040:' . self::AUTHOR . ':' . self::IDENTIFIER;
    private const RELAY = 'wss://highlights.example.test';

    /** @dataProvider exactReferenceCases */
    public function testExactEventWinsOverCoordinateEvenWhenNotStoredLocally(string $template, bool $local): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findById')->with(self::ID)
            ->willReturn($local ? $this->event(self::ID, 'Exact revision title') : null);
        $events->expects(self::never())->method('findByNaddr');

        $html = $this->renderHighlight($template, ['event_ref' => self::ID], $events);
        $exactReference = (new NostrNip19Service())->encodeNote(self::ID);

        self::assertStringContainsString('highlighted passage', $html);
        self::assertStringContainsString('href="/e/' . $exactReference . '"', $html);
        self::assertStringNotContainsString($this->address(), $html);
        self::assertStringNotContainsString('class="article-preview"', $html);
        if ($local) {
            self::assertStringContainsString('Exact revision title', $html);
            self::assertStringContainsString('event-preview__title', $html);
            self::assertStringNotContainsString('nostr--deferred-embed', $html);
        } else {
            self::assertStringContainsString('data-nostr="' . $exactReference . '"', $html);
            self::assertStringContainsString('nostr--deferred-embed', $html);
            self::assertStringContainsString('Referenced note not yet available', $html);
        }
    }

    public static function exactReferenceCases(): iterable
    {
        foreach (self::templates() as $name => $template) {
            yield $name . ' local exact revision' => [$template, true];
            yield $name . ' missing exact revision' => [$template, false];
        }
    }

    /** @dataProvider coordinateFallbackCases */
    public function testCoordinatePreviewRemainsFallbackWithoutExactReference(string $template, array $reference): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findById');
        $events->expects(self::once())->method('findByNaddr')
            ->with(30040, self::AUTHOR, self::IDENTIFIER)
            ->willReturn($this->event(str_repeat('c', 64), 'Latest coordinate title'));

        $html = $this->renderHighlight($template, $reference, $events);

        self::assertStringContainsString('highlighted passage', $html);
        self::assertStringContainsString('class="article-preview"', $html);
        self::assertStringContainsString('Latest coordinate title', $html);
        self::assertStringContainsString('event-preview__title', $html);
        self::assertStringContainsString('href="/e/' . $this->address() . '"', $html);
        self::assertStringNotContainsString((new NostrNip19Service())->encodeNote(self::ID), $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
    }

    public static function coordinateFallbackCases(): iterable
    {
        foreach (self::templates() as $name => $template) {
            yield $name . ' omitted event reference' => [$template, []];
            yield $name . ' null event reference' => [$template, ['event_ref' => null]];
            yield $name . ' empty event reference' => [$template, ['event_ref' => '']];
        }
    }

    private static function templates(): array
    {
        return [
            'feed card' => 'partial/_highlight_feed_card.html.twig',
            'with source' => 'partial/_highlight_with_source.html.twig',
        ];
    }

    private function address(): string
    {
        return (new NostrNip19Service())->encodeAddr(self::AUTHOR, self::IDENTIFIER, 30040, [self::RELAY]);
    }

    private function event(string $id, string $title): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setKind(30040);
        $event->setPubkey(self::AUTHOR);
        $event->setCreatedAt(123);
        $event->setTags([['d', self::IDENTIFIER], ['title', $title]]);
        $event->setContent('');
        return $event;
    }

    private function renderHighlight(string $template, array $reference, EventRepository $events): string
    {
        self::bootKernel();
        $container = self::getContainer();
        $container->set(EventRepository::class, $events);
        $articles = $this->createMock(ArticleRepository::class);
        $articles->expects(self::never())->method('findOneBy');
        $container->set(ArticleRepository::class, $articles);
        $container->set(EventPreviewAccessPolicy::class, new EventPreviewAccessPolicy(
            $this->createMock(ContentAuthorAccessPolicy::class),
            $this->createMock(DeletedEventRepository::class),
            $this->createMock(HiddenCoordinateRepository::class),
            new RecordIdentityService(),
            $this->createMock(Security::class),
            $this->createMock(UserMuteListService::class),
            new NostrKeyService(),
            new NullLogger(),
            new ArticleAccessService(new NostrIdentityService(
                $this->createMock(Nip19DecoderInterface::class),
                $this->createMock(Nip19EncoderInterface::class),
            )),
        ));
        $container->set(EventPresentationFactory::class, new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()));
        $container->set(NostrEventVerifier::class, new NostrEventVerifier(new NostrSigner(
            $this->createMock(SignatureServiceInterface::class),
        )));
        $container->set(LoggerInterface::class, new NullLogger());

        return $container->get(Environment::class)->render($template, [
            'highlight' => array_merge([
                'pubkey' => null,
                'created_at' => 123,
                'content' => 'highlighted passage',
                'context' => null,
                'article_ref' => self::COORDINATE,
                'naddr' => $this->address(),
                'relayHints' => [self::RELAY],
            ], $reference),
        ]);
    }

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new HighlightReferenceTestKernel($options['environment'] ?? 'test', $options['debug'] ?? true);
    }
}

final class HighlightReferenceTestKernel extends Kernel
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
            'test' => true, 'secret' => 'highlight-reference-test',
            'property_info' => ['with_constructor_extractor' => true],
        ]);
        $container->import($this->getProjectDir() . '/config/packages/translation.yaml');
        $container->extension('twig', ['default_path' => '%kernel.project_dir%/templates', 'strict_variables' => true]);
        $container->extension('twig_component', [
            'anonymous_template_directory' => 'components/',
            'defaults' => ['App\\Twig\\Components\\' => 'components/'],
        ]);
        $services = $container->services();
        $services->set(\App\Twig\Filters::class)->autowire()->autoconfigure();
        $services->set(\App\Twig\NostrExtension::class)->autowire()->autoconfigure();
        foreach ([EventRepository::class, ArticleRepository::class, EventPreviewAccessPolicy::class, EventPresentationFactory::class, NostrEventVerifier::class, LoggerInterface::class] as $dependency) {
            $services->set($dependency)->synthetic()->public();
        }
        $services->set(AuthoredArticlePreview::class)->autowire();
        $services->set(NostrEmbed::class)->autowire()->autoconfigure();
        $services->set(HighlightReferenceTemplateExtension::class)->autowire()->autoconfigure();
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/newsroom-highlight-reference-' . getmypid() . '/' . $this->getEnvironment();
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/newsroom-highlight-reference-' . getmypid() . '/log';
    }
}

/** Compile unrelated body/source-URL branches; these tests render neither. */
final class HighlightReferenceTemplateExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('markdown_to_html', static fn (string $content): string => $content, ['is_safe' => ['html']])];
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('ux_icon', static fn (): string => '', ['is_safe' => ['html']])];
    }
}
