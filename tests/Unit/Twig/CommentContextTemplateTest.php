<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Service\Nostr\NostrNip19Service;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Twig\Extension\AbstractExtension;
use Twig\Environment;
use Twig\TwigFilter;

final class CommentContextTemplateTest extends KernelTestCase
{
    private const AUTHOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const PARENT = '30040:' . self::AUTHOR . ':parent:edition';
    private const ROOT = '30040:' . self::AUTHOR . ':root:edition';
    private const PARENT_RELAY = 'wss://parent.example.test';
    private const ROOT_RELAY = 'wss://root.example.test';

    public function testParentAndRootAddressReferencesKeepFirstOccurrenceAndRelayHints(): void
    {
        $html = $this->render([
            ['A', self::ROOT, self::ROOT_RELAY],
            ['a', self::PARENT, self::PARENT_RELAY],
            ['a', '30040:' . self::AUTHOR . ':later-parent', 'wss://ignored-parent.example.test'],
            ['A', '30040:' . self::AUTHOR . ':later-root', 'wss://ignored-root.example.test'],
        ]);
        $parent = (new NostrNip19Service())->encodeAddr(self::AUTHOR, 'parent:edition', 30040, [self::PARENT_RELAY]);
        $root = (new NostrNip19Service())->encodeAddr(self::AUTHOR, 'root:edition', 30040, [self::ROOT_RELAY]);

        self::assertSame(2, substr_count($html, 'data-controller="nostr--deferred-embed"'));
        self::assertStringContainsString('data-nostr="' . $parent . '"', $html);
        self::assertStringContainsString('data-nostr="' . $root . '"', $html);
        self::assertLessThan(strpos($html, 'data-nostr="' . $root . '"'), strpos($html, 'data-nostr="' . $parent . '"'));
        self::assertStringNotContainsString('later-parent', $html);
        self::assertStringNotContainsString('later-root', $html);

        $decodedParent = (new NostrNip19Service())->decode($parent);
        self::assertSame(self::PARENT_RELAY, $decodedParent['relays'][0]);
        self::assertSame('parent:edition', $decodedParent['identifier']);
        self::assertSame('30040', (string) $decodedParent['kind']);
    }

    public function testEqualParentAndRootAddressReferencesRenderOnlyOnePreview(): void
    {
        $html = $this->render([
            ['A', self::PARENT, self::ROOT_RELAY],
            ['a', self::PARENT, self::PARENT_RELAY],
        ]);

        self::assertSame(1, substr_count($html, 'data-nostr="'));
        self::assertStringContainsString('data-nostr="' . (new NostrNip19Service())->encodeAddr(
            self::AUTHOR,
            'parent:edition',
            30040,
            [self::PARENT_RELAY],
        ) . '"', $html);
    }

    public function testFirstRootReferenceAcrossTypesWins(): void
    {
        $rootId = str_repeat('d', 64);
        $html = $this->render([
            ['E', $rootId],
            ['A', self::ROOT],
            ['a', self::PARENT],
        ]);

        self::assertStringContainsString('data-nostr="' . (new NostrNip19Service())->encodeNote($rootId) . '"', $html);
        self::assertStringNotContainsString('data-nostr="' . (new NostrNip19Service())->encodeAddr(
            self::AUTHOR,
            'root:edition',
            30040,
        ) . '"', $html);
    }

    public function testMalformedFirstRootDoesNotFallThroughToLaterRootOrParent(): void
    {
        $html = $this->render([
            ['A', ''],
            ['E', str_repeat('d', 64)],
            ['a', self::PARENT],
        ]);

        self::assertStringContainsString('Target preview restricted', $html);
        self::assertSame(1, substr_count($html, 'data-nostr='));
        self::assertStringNotContainsString('data-nostr="' . (new NostrNip19Service())->encodeNote(str_repeat('d', 64)) . '"', $html);
        self::assertStringContainsString('data-nostr="' . (new NostrNip19Service())->encodeAddr(
            self::AUTHOR,
            'parent:edition',
            30040,
        ) . '"', $html);
    }

    public function testMalformedParentAddressFailsClosedWithoutFallingBackToEventParent(): void
    {
        $html = $this->render([
            ['a', '30040:not-a-pubkey:bad'],
            ['e', str_repeat('d', 64)],
        ]);

        self::assertStringContainsString('Target preview restricted', $html);
        self::assertStringNotContainsString('data-nostr=', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
    }

    public function testEmptyParentAddressFailsClosedWithoutFallingBackToEventParent(): void
    {
        $html = $this->render([
            ['a', ''],
            ['e', str_repeat('d', 64)],
        ]);

        self::assertStringContainsString('Target preview restricted', $html);
        self::assertStringNotContainsString('data-nostr=', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
    }

    public function testInvalidFirstParentAddressCannotBeReplacedByLaterAddress(): void
    {
        $html = $this->render([
            ['a', ''],
            ['a', self::PARENT],
            ['e', str_repeat('d', 64)],
        ]);

        self::assertStringContainsString('Target preview restricted', $html);
        self::assertStringNotContainsString('data-nostr=', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
    }

    public function testEventParentKeepsPrecedenceOverExternalParentRegardlessOfOrder(): void
    {
        $eventId = str_repeat('d', 64);
        $html = $this->render([
            ['i', 'external:parent'],
            ['e', $eventId],
        ]);

        self::assertStringContainsString('data-nostr="' . (new NostrNip19Service())->encodeNote($eventId) . '"', $html);
        self::assertStringNotContainsString('external:parent', $html);
    }

    public function testDifferentSelectedAddressParentDoesNotSuppressMatchingEventRoot(): void
    {
        $rootId = str_repeat('d', 64);
        $html = $this->render([
            ['E', $rootId],
            ['e', $rootId],
            ['a', self::PARENT],
        ]);

        self::assertSame(2, substr_count($html, 'data-nostr='));
        self::assertStringContainsString('data-nostr="' . (new NostrNip19Service())->encodeAddr(
            self::AUTHOR,
            'parent:edition',
            30040,
        ) . '"', $html);
        self::assertStringContainsString('data-nostr="' . (new NostrNip19Service())->encodeNote($rootId) . '"', $html);
    }

    public function testEmptyEventParentFailsClosedWithoutFallingBackToExternalParent(): void
    {
        $html = $this->render([
            ['e', ''],
            ['i', 'https://example.test/parent'],
        ]);

        self::assertStringContainsString('Target preview restricted', $html);
        self::assertStringNotContainsString('https://example.test/parent', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
    }

    public function testExternalIdentityAndCommentContentRemainRendered(): void
    {
        $html = $this->render([
            ['i', 'external:identity'],
        ]);

        self::assertStringContainsString('external:identity', $html);
        self::assertStringNotContainsString('href="external:identity"', $html);
        self::assertStringContainsString('<div>comment body</div>', $html);
        self::assertStringNotContainsString('data-controller="nostr--deferred-embed"', $html);
    }

    public function testUnsafeExternalIdentityIsRenderedAsTextInParentAndRoot(): void
    {
        $html = $this->render([
            ['i', 'javascript:alert(1)'],
            ['I', 'javascript:alert(2)'],
        ]);

        self::assertStringContainsString('javascript:alert(1)', $html);
        self::assertStringContainsString('javascript:alert(2)', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
    }

    /** @param list<list<string>> $tags */
    private function render(array $tags): string
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->render('event/_kind1111_comment.html.twig', [
            'event' => (object) [
                'tags' => $tags,
                'content' => 'comment body',
                'kind' => 1111,
                'id' => str_repeat('b', 64),
                'pubkey' => self::AUTHOR,
            ],
        ]);
    }

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new CommentContextTemplateTestKernel($options['environment'] ?? 'test', $options['debug'] ?? true);
    }
}

#[AsTwigComponent(name: 'Molecules:NostrEmbed', template: 'components/Molecules/NostrEmbed.html.twig')]
final class CommentContextEmbedFixture
{
    public string $bech = '';
    public string $type = '';
    public string $shortBech = '';
    public string $href = '';
    public string $label = 'event';
    public bool $restricted = false;
    public bool $sensitive = false;
    public mixed $presentation = null;

    public function mount(string $bech, string $type): void
    {
        $this->bech = $bech;
        $this->type = $type;
        $this->shortBech = substr($bech, 0, 12) . '…' . substr($bech, -8);
        $this->href = '/e/' . $bech;
    }
}

#[AsTwigComponent(name: 'Atoms:Content', template: 'components/Atoms/Content.html.twig')]
final class CommentContextContentFixture
{
    public string $parsed = '';

    public function mount(string $content): void
    {
        $this->parsed = $content;
    }
}

final class CommentContextTemplateTestKernel extends Kernel
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
            'secret' => 'comment-context-template-test',
            'property_info' => ['with_constructor_extractor' => true],
        ]);
        $container->import($this->getProjectDir() . '/config/packages/translation.yaml');
        $container->extension('twig', [
            'default_path' => '%kernel.project_dir%/templates',
            'strict_variables' => true,
        ]);
        $container->extension('twig_component', [
            'anonymous_template_directory' => 'components/',
            'defaults' => ['App\\Twig\\Components\\' => 'components/'],
        ]);
        $services = $container->services();
        $services->set(\App\Twig\Filters::class)->autowire()->autoconfigure();
        $services->set(CommentContextEmbedFixture::class)->autowire()->autoconfigure();
        $services->set(CommentContextContentFixture::class)->autowire()->autoconfigure();
        $services->set(CommentContextTemplateExtension::class)->autowire()->autoconfigure();
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/newsroom-comment-context-' . getmypid() . '/' . $this->getEnvironment();
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/newsroom-comment-context-' . getmypid() . '/log';
    }
}

final class CommentContextTemplateExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('resolve_nostr_embeds', static fn (string $content): string => $content, ['is_safe' => ['html']]),
        ];
    }
}
