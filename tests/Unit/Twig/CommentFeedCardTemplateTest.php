<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Entity\Event;
use App\Repository\ArticleRepository;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\ArticleAccessService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use App\Twig\Components\Molecules\NostrEmbed;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class CommentFeedCardTemplateTest extends KernelTestCase
{
    private const AUTHOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const ROOT = '30040:' . self::AUTHOR . ':root:edition';
    private const PARENT = '30040:' . self::AUTHOR . ':parent:edition';
    private const RELAY = 'wss://comments.example.test';

    public function testCallerRendersRootAddressAsLocalSharedPreview(): void
    {
        $target = $this->event(30040, [['d', 'root:edition'], ['title', 'Root title']]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findByNaddr')->with(30040, self::AUTHOR, 'root:edition')->willReturn($target);
        $events->expects(self::never())->method('findById');
        $html = $this->renderCard([
            ['a', self::PARENT], ['A', self::ROOT], ['A', self::PARENT], ['e', self::ID],
        ], $events);

        self::assertStringContainsString('bookmark-event-card card mb-3', $html);
        self::assertStringContainsString('comment-callout-header', $html);
        self::assertStringContainsString(self::AUTHOR, $html);
        self::assertStringContainsString('commented:', $html);
        self::assertStringContainsString('<div>comment body</div>', $html);
        self::assertStringContainsString('Root title', $html);
        self::assertStringContainsString('event-preview__title', $html);
        self::assertStringContainsString('View Full Event', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
    }

    public function testColdParentAddressKeepsExactEncodedIdentityAndDeferredPlaceholder(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findByNaddr')->with(30040, self::AUTHOR, 'parent:edition')->willReturn(null);
        $events->expects(self::never())->method('findById');
        $html = $this->renderCard([['a', self::PARENT, self::RELAY]], $events);
        $reference = (new NostrNip19Service())->encodeAddr(self::AUTHOR, 'parent:edition', 30040, [self::RELAY]);
        $decoded = (new NostrNip19Service())->decode($reference);

        self::assertSame('parent:edition', $decoded['identifier']);
        self::assertSame(self::AUTHOR, $decoded['pubkey']);
        self::assertSame(30040, $decoded['kind']);
        self::assertSame([self::RELAY], $decoded['relays']);
        self::assertStringContainsString('data-nostr="' . $reference . '"', $html);
        self::assertStringContainsString('data-controller="nostr--deferred-embed"', $html);
        self::assertStringContainsString('href="/e/' . $reference . '"', $html);
    }

    public function testDeniedLocalAddressDoesNotExposeMetadataOrStartDeferredPreview(): void
    {
        $target = $this->event(30040, [['d', 'root:edition'], ['title', 'Secret title']]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findByNaddr')->with(30040, self::AUTHOR, 'root:edition')->willReturn($target);
        $html = $this->renderCard([['A', self::ROOT]], $events, true);

        self::assertStringContainsString('nostr-placeholder-label', $html);
        self::assertStringNotContainsString('Secret title', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
    }

    public function testRootEventTakesPrecedenceOverAddressParent(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findById')->with(self::ID)->willReturn(null);
        $events->expects(self::never())->method('findByNaddr');
        $html = $this->renderCard([
            ['a', self::PARENT],
            ['E', self::ID],
        ], $events);

        self::assertStringContainsString('data-nostr="' . (new NostrNip19Service())->encodeNote(self::ID) . '"', $html);
        self::assertStringNotContainsString('root:edition', $html);
        self::assertStringContainsString('Referenced note not yet available', $html);
        self::assertStringNotContainsString('event_workspace.target.restricted', $html);
    }

    public function testAddressParentRetainsPrecedenceOverEventParentRegardlessOfTagOrder(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findByNaddr')->with(30040, self::AUTHOR, 'parent:edition')->willReturn(null);
        $events->expects(self::never())->method('findById');
        $html = $this->renderCard([
            ['e', self::ID],
            ['a', self::PARENT],
        ], $events);
        $address = (new NostrNip19Service())->encodeAddr(self::AUTHOR, 'parent:edition', 30040);

        self::assertStringContainsString('data-nostr="' . $address . '"', $html);
        self::assertStringNotContainsString('data-nostr="' . (new NostrNip19Service())->encodeNote(self::ID) . '"', $html);
    }

    public function testEmptyRootAddressFailsClosedWithoutFallingBackToParent(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findByNaddr');
        $events->expects(self::never())->method('findById');
        $html = $this->renderCard([
            ['A', ''],
            ['e', self::ID],
        ], $events);

        self::assertStringContainsString('nostr-placeholder-label', $html);
        self::assertStringNotContainsString('data-nostr=', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
    }

    public function testEmptyParentAddressFailsClosedWithoutFallingBackToEventParent(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findByNaddr');
        $events->expects(self::never())->method('findById');
        $html = $this->renderCard([
            ['a', ''],
            ['e', self::ID],
        ], $events);

        self::assertStringContainsString('Target preview restricted', $html);
        self::assertStringNotContainsString('data-nostr=', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
    }

    public function testColdRootAddressKeepsRelayHintInEncodedIdentity(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findByNaddr')->with(30040, self::AUTHOR, 'root:edition')->willReturn(null);
        $events->expects(self::never())->method('findById');
        $html = $this->renderCard([['A', self::ROOT, self::RELAY]], $events);
        $reference = (new NostrNip19Service())->encodeAddr(self::AUTHOR, 'root:edition', 30040, [self::RELAY]);

        self::assertSame([self::RELAY], (new NostrNip19Service())->decode($reference)['relays']);
        self::assertStringContainsString('data-nostr="' . $reference . '"', $html);
    }

    /** @dataProvider invalidNonAddressRootValues */
    public function testMalformedEventOrExternalRootFailsClosedWithoutFallingBack(array $root): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findByNaddr');
        $events->expects(self::never())->method('findById');
        $html = $this->renderCard([$root, ['a', self::PARENT], ['e', self::ID]], $events);

        self::assertStringContainsString('Target preview restricted', $html);
        self::assertStringNotContainsString('data-nostr=', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
    }

    public static function invalidNonAddressRootValues(): iterable
    {
        yield 'non-string event ID' => [['E', ['bad']]];
        yield 'empty event ID' => [['E', '']];
        yield 'non-string external identity' => [['I', ['bad']]];
    }

    public function testSafeFilterPreservesColonAndEmptyIdentifiersAndLegacyFilterRemainsUnchanged(): void
    {
        $filters = new \App\Twig\Filters();
        foreach (['book:edition', ''] as $identifier) {
            $encoded = $filters->naddrEncodeCoordinateOrNull('30040:' . strtoupper(self::AUTHOR) . ':' . $identifier);
            self::assertNotNull($encoded);
            $decoded = (new NostrNip19Service())->decode($encoded);
            self::assertSame($identifier, $decoded['identifier']);
            self::assertSame(self::AUTHOR, $decoded['pubkey']);
            self::assertSame(30040, $decoded['kind']);
        }
        self::assertSame('bad', $filters->naddrEncodeCoordinate('bad'));
        self::assertNull($filters->naddrEncodeCoordinateOrNull('bad'));
    }

    /** @dataProvider invalidCoordinates */
    public function testMalformedRootFailsClosedWithoutFallingBack(mixed $coordinate): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findByNaddr');
        $events->expects(self::never())->method('findById');
        $html = $this->renderCard([['A', $coordinate], ['a', self::PARENT], ['e', self::ID]], $events);

        self::assertStringContainsString('nostr-placeholder-label', $html);
        self::assertStringNotContainsString('data-nostr=', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
        self::assertStringContainsString('comment body', $html);
    }

    public static function invalidCoordinates(): iterable
    {
        yield 'malformed' => ['not-a-coordinate'];
        yield 'bad pubkey' => ['30040:bad:book'];
        yield 'overflow kind' => ['4294967296:' . self::AUTHOR . ':book'];
        yield 'oversized identifier' => ['30040:' . self::AUTHOR . ':' . str_repeat('x', 256)];
        yield 'non-string value' => [['bad']];
        yield 'encoding loses non-ascii identifier' => ['30040:' . self::AUTHOR . ':é'];
        yield 'empty root coordinate' => [''];
    }

    public function testExactExternalAndStandaloneBranchesAreUnchanged(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findById')->with(self::ID)->willReturn(null);
        $events->expects(self::never())->method('findByNaddr');
        $html = $this->renderCard([['e', str_repeat('c', 64)], ['E', self::ID], ['i', 'https://example.test']], $events);
        $reference = (new NostrNip19Service())->encodeNote(self::ID);
        self::assertStringContainsString('data-nostr="' . $reference . '"', $html);
        self::assertStringNotContainsString('comment-callout-external', $html);

        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findById');
        $events->expects(self::never())->method('findByNaddr');
        $html = $this->renderCard([['I', 'https://example.test/root'], ['i', 'https://example.test/parent']], $events);
        self::assertStringContainsString('href="https://example.test/root"', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);

        $html = $this->renderCard([['I', 'javascript:alert(1)']], $events);
        self::assertStringContainsString('javascript:alert(1)', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
        $html = $this->renderCard([['I', 'https://']], $events);
        self::assertStringContainsString('https://', $html);
        self::assertStringNotContainsString('href="https://"', $html);

        $html = $this->renderCard([], $events);
        self::assertSame(2, substr_count($html, '<div>comment body</div>'));
        self::assertStringContainsString('class="event-content"', $html);
    }

    private function event(int $kind, array $tags): Event
    {
        $event = new Event();
        $event->setId(self::ID);
        $event->setKind($kind);
        $event->setPubkey(self::AUTHOR);
        $event->setCreatedAt(123);
        $event->setTags($tags);
        $event->setContent('comment body');
        return $event;
    }

    private function renderCard(array $tags, EventRepository $events, bool $suppressed = false): string
    {
        self::bootKernel();
        $container = self::getContainer();
        $container->set(EventRepository::class, $events);
        $articles = $this->createMock(ArticleRepository::class);
        $articles->expects(self::never())->method('findOneBy');
        $container->set(ArticleRepository::class, $articles);
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        if ($suppressed) {
            $authors->method('assertReadable')->willThrowException(new NotFoundHttpException());
        }
        $policy = new EventPreviewAccessPolicy(
            $authors,
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
        );
        $container->set(EventPreviewAccessPolicy::class, $policy);
        $container->set(EventPresentationFactory::class, new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()));
        $container->set(LoggerInterface::class, new NullLogger());
        return $container->get(Environment::class)->render('partial/_bookmark_event_card.html.twig', [
            'event' => $this->event(1111, $tags),
        ]);
    }

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new CommentFeedCardTestKernel($options['environment'] ?? 'test', $options['debug'] ?? true);
    }
}

#[AsTwigComponent(name: 'Molecules:UserFromNpub', template: 'components/Atoms/Content.html.twig')]
final class CommentFeedAuthorFixture
{
    public string $parsed = '';

    public function mount(string $ident): void
    {
        $this->parsed = $ident;
    }
}

#[AsTwigComponent(name: 'Atoms:Content', template: 'components/Atoms/Content.html.twig')]
final class CommentFeedContentFixture
{
    public string $parsed = '';

    public function mount(string $content): void
    {
        $this->parsed = $content;
    }
}

final class CommentFeedCardTestKernel extends Kernel
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
            'test' => true, 'secret' => 'comment-feed-card-test',
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
        foreach ([EventRepository::class, ArticleRepository::class, EventPreviewAccessPolicy::class, EventPresentationFactory::class, LoggerInterface::class] as $dependency) {
            $services->set($dependency)->synthetic()->public();
        }
        $services->set(NostrEmbed::class)->autowire()->autoconfigure();
        $services->set(CommentFeedAuthorFixture::class)->autowire()->autoconfigure();
        $services->set(CommentFeedContentFixture::class)->autowire()->autoconfigure();
        $services->set(CommentFeedTemplateExtension::class)->autowire()->autoconfigure();
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/newsroom-comment-feed-card-' . getmypid() . '/' . $this->getEnvironment();
    }
}

final class CommentFeedTemplateExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [new TwigFilter('resolve_nostr_embeds', static fn (string $content): string => $content, ['is_safe' => ['html']])];
    }
}
