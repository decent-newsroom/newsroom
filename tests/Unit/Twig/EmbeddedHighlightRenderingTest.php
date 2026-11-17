<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Dto\UserMetadata;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\Reader\HighlightSourceVisibility;
use App\Tests\Unit\Support\HighlightSourceVisibilityFactory;
use App\Twig\NostrEmbedRuntime;
use App\Unfold\MarkdownConverterAdapter;
use App\Util\CommonMark\Converter;
use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Content\PostData;
use DecentNewsroom\UnfoldBundle\Contract\CommentProviderInterface;
use DecentNewsroom\UnfoldBundle\Contract\ProfileMetadata;
use DecentNewsroom\UnfoldBundle\Contract\ProfileMetadataProviderInterface;
use DecentNewsroom\UnfoldBundle\Theme\ContextBuilder;
use nostriphant\NIP19\Bech32;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;

final class EmbeddedHighlightRenderingTest extends KernelTestCase
{
    use HighlightSourceVisibilityFactory;

    private const HIGHLIGHT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const NESTED_HIGHLIGHT = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const AUTHOR = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const SOURCE = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';
    private const NESTED_SOURCE = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    /** @var array<string, Event> */
    private array $events = [];
    /** @var array<string, Event> */
    private array $availableSources = [];
    /** @var list<bool> */
    private array $metadataRefreshes = [];
    private int $bulkEventLookups = 0;
    private bool $signedIn = false;
    private Converter $converter;
    private NostrEmbedRuntime $runtime;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $repository = $this->createMock(EventRepository::class);
        $repository->method('findByIds')->willReturnCallback(function (array $ids): array {
            ++$this->bulkEventLookups;
            return array_intersect_key($this->events, array_flip($ids));
        });
        $repository->method('findById')->willReturnCallback(fn (string $id): ?Event => $this->events[$id] ?? null);
        $repository->method('findHighlightSourceTargets')->willReturnCallback(function (array $coordinates, array $ids): array {
            return ['coordinates' => [], 'eventIds' => array_intersect_key($this->availableSources, array_flip($ids))];
        });

        $metadata = $this->createMock(RedisCacheService::class);
        $metadata->method('getMetadata')->willReturnCallback(function (string $pubkey, bool $refreshOnMiss = true): UserMetadata {
            $this->metadataRefreshes[] = $refreshOnMiss;
            return new UserMetadata(name: 'Local author');
        });
        $metadata->method('getMultipleMetadata')->willReturnCallback(function (array $pubkeys, bool $refreshOnMiss = true): array {
            $this->metadataRefreshes[] = $refreshOnMiss;
            return array_fill_keys($pubkeys, new UserMetadata(name: 'Local author'));
        });

        $security = $this->createMock(Security::class);
        $viewer = $this->createMock(UserInterface::class);
        $security->method('getUser')->willReturnCallback(fn (): ?UserInterface => $this->signedIn ? $viewer : null);
        $access = $this->makeHighlightPreviewAccess($security);
        $articles = $this->createMock(ArticleRepository::class);
        $visibility = $this->makeHighlightVisibility($repository, articles: $articles, security: $security, access: $access);

        $container = self::getContainer();
        $container->set(EventRepository::class, $repository);
        $container->set(ArticleRepository::class, $articles);
        $container->set(RedisCacheService::class, $metadata);
        $container->set(Security::class, $security);
        $container->set(EventPreviewAccessPolicy::class, $access);
        $container->set(HighlightSourceVisibility::class, $visibility);
        $this->converter = $container->get(Converter::class);
        $this->runtime = $container->get(NostrEmbedRuntime::class);

        $this->events[self::HIGHLIGHT] = $this->event(self::HIGHLIGHT, KindsEnum::HIGHLIGHTS->value, 'Unique outer excerpt', [
            ['e', self::SOURCE],
        ]);
    }

    public function testCachedPlaceholderUsesCurrentViewerAndSourceWithoutMutatingSharedHtml(): void
    {
        $cached = $this->converter->convertToHTML($this->rawContent(), 'markdown', 30023);
        self::assertStringContainsString('data-nostr-type="highlight-nevent"', $cached);
        self::assertStringNotContainsString('Unique outer excerpt', $cached);

        $this->metadataRefreshes = [];
        $guestMissing = $this->render($cached);
        self::assertStringNotContainsString('Unique outer excerpt', $guestMissing);
        self::assertStringContainsString('data-highlight-embed', $guestMissing);

        $this->availableSources[self::SOURCE] = $this->event(self::SOURCE, 1, 'Local source', []);
        $guestAvailable = $this->render($cached);
        self::assertStringContainsString('Unique outer excerpt', $guestAvailable);
        self::assertStringContainsString('data-turbo-temporary', $guestAvailable);
        self::assertNotContains(true, $this->metadataRefreshes);

        unset($this->availableSources[self::SOURCE]);
        $this->signedIn = true;
        $this->metadataRefreshes = [];
        self::assertStringContainsString('Unique outer excerpt', $this->render($cached));
        self::assertContains(true, $this->metadataRefreshes);
        $this->signedIn = false;
        $this->metadataRefreshes = [];
        self::assertStringNotContainsString('Unique outer excerpt', $this->render($cached));
        self::assertNotContains(true, $this->metadataRefreshes);
        self::assertStringNotContainsString('Unique outer excerpt', $cached);
    }

    /** @dataProvider legacyContentKinds */
    public function testLegacyCachedCardIsRebuiltLocallyForGuests(int $kind): void
    {
        $legacy = '<p>Intro</p><div class="embedded-event-card">Unique outer excerpt</div>';
        $raw = $this->rawContent();
        $this->metadataRefreshes = [];

        $missing = $this->runtime->resolveEmbeds($legacy, $raw, $kind, [['d', 'source']]);
        self::assertStringContainsString('Intro', $missing);
        self::assertStringNotContainsString('Unique outer excerpt', $missing);

        $this->availableSources[self::SOURCE] = $this->event(self::SOURCE, 1, 'Local source', []);
        $available = $this->runtime->resolveEmbeds($legacy, $raw, $kind, [['d', 'source']]);
        self::assertStringContainsString('Unique outer excerpt', $available);
        self::assertNotContains(true, $this->metadataRefreshes);

        $this->signedIn = true;
        self::assertSame($legacy, $this->runtime->resolveEmbeds($legacy, $raw, $kind, [['d', 'source']]));
    }

    public static function legacyContentKinds(): iterable
    {
        yield 'article markdown' => [30023];
        yield 'chapter asciidoc' => [30041];
    }

    public function testNestedHighlightIsCheckedIndependentlyAndCyclesAreBounded(): void
    {
        $this->events[self::HIGHLIGHT]->setContent('Unique outer excerpt' . "\n\nnostr:" . $this->reference(self::NESTED_HIGHLIGHT));
        $this->events[self::NESTED_HIGHLIGHT] = $this->event(
            self::NESTED_HIGHLIGHT,
            KindsEnum::HIGHLIGHTS->value,
            'Unique inner excerpt' . "\n\nnostr:" . $this->reference(self::HIGHLIGHT),
            [['e', self::NESTED_SOURCE]],
        );
        $this->availableSources[self::SOURCE] = $this->event(self::SOURCE, 1, 'Outer source', []);
        $cached = $this->converter->convertToHTML($this->rawContent(), 'markdown', 30023);
        self::assertStringNotContainsString('Unique outer excerpt', $cached);
        self::assertStringNotContainsString('Unique inner excerpt', $cached);

        $guest = $this->render($cached);
        self::assertStringContainsString('Unique outer excerpt', $guest);
        self::assertStringNotContainsString('Unique inner excerpt', $guest);

        $this->availableSources[self::NESTED_SOURCE] = $this->event(self::NESTED_SOURCE, 1, 'Inner source', []);
        $guest = $this->render($cached);
        self::assertSame(1, substr_count($guest, 'Unique outer excerpt'));
        self::assertSame(1, substr_count($guest, 'Unique inner excerpt'));
    }

    public function testHighlightBodyUsesLocalMetadataAndLinksInsteadOfFetchingRemoteEmbeds(): void
    {
        $url = 'https://www.youtube.com/watch?v=abcdefghijk';
        $npub = (string) Bech32::npub(self::AUTHOR);
        $this->events[self::HIGHLIGHT]->setContent("Unique outer excerpt\n\n$url\n\nnostr:$npub");
        $this->availableSources[self::SOURCE] = $this->event(self::SOURCE, 1, 'Local source', []);
        $cached = $this->converter->convertToHTML($this->rawContent(), 'markdown', 30023);

        $this->metadataRefreshes = [];
        $html = $this->render($cached);

        self::assertStringContainsString('Unique outer excerpt', $html);
        self::assertStringContainsString('href="' . $url . '"', $html);
        self::assertStringNotContainsString('<iframe', $html);
        self::assertNotEmpty($this->metadataRefreshes);
        self::assertNotContains(true, $this->metadataRefreshes);
    }

    public function testSignedInHighlightBodyKeepsNormalMetadataRefresh(): void
    {
        $this->events[self::HIGHLIGHT]->setContent(
            'Unique outer excerpt' . "\n\nnostr:" . (string) Bech32::npub(self::AUTHOR),
        );
        $cached = $this->converter->convertToHTML($this->rawContent(), 'markdown', 30023);
        $this->signedIn = true;
        $this->metadataRefreshes = [];

        self::assertStringContainsString('Unique outer excerpt', $this->render($cached));
        self::assertContains(true, $this->metadataRefreshes);
    }

    public function testLocalRenderingScopeIsRestoredAfterFailure(): void
    {
        try {
            $this->converter->withLocalRendering(static function (): void {
                throw new \RuntimeException('Expected failure');
            });
            self::fail('The exception should propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Expected failure', $exception->getMessage());
        }

        $this->metadataRefreshes = [];
        $this->converter->convertToHTML('nostr:' . (string) Bech32::npub(self::AUTHOR), 'markdown');
        self::assertContains(true, $this->metadataRefreshes);
    }

    public function testUnfoldResolvesRealHostAdapterAfterSharedSanitizedCache(): void
    {
        $adapter = new MarkdownConverterAdapter($this->converter, $this->runtime);
        $profiles = $this->createMock(ProfileMetadataProviderInterface::class);
        $profiles->method('getMetadata')->willReturn(new ProfileMetadata(name: 'Writer'));
        $comments = $this->createMock(CommentProviderInterface::class);
        $comments->method('findByCoordinate')->willReturn([]);
        $builder = new ContextBuilder($adapter, new ArrayAdapter(), $profiles, $comments);
        $site = new SiteConfig('30040:' . self::AUTHOR . ':root', 'Publication', '', null, [], self::AUTHOR);
        $post = new PostData('story', 'Title', '', $this->rawContent(), null, 1, self::AUTHOR,
            '30023:' . self::AUTHOR . ':story', kind: 30023);

        $missing = $builder->buildPostContext($site, [], $post)['post']['html'];
        self::assertStringNotContainsString('Unique outer excerpt', $missing);
        self::assertStringContainsString('data-highlight-embed', $missing);
        $lookupsAfterConversion = $this->bulkEventLookups;

        $this->availableSources[self::SOURCE] = $this->event(self::SOURCE, 1, 'Local source', []);
        $available = $builder->buildPostContext($site, [], $post)['post']['html'];
        self::assertStringContainsString('Unique outer excerpt', $available);
        self::assertSame($lookupsAfterConversion, $this->bulkEventLookups);

        unset($this->availableSources[self::SOURCE]);
        $this->signedIn = true;
        self::assertStringContainsString('Unique outer excerpt', $builder->buildPostContext($site, [], $post)['post']['html']);
        $this->signedIn = false;
        self::assertStringNotContainsString('Unique outer excerpt', $builder->buildPostContext($site, [], $post)['post']['html']);
        self::assertSame($lookupsAfterConversion, $this->bulkEventLookups);
    }

    private function render(string $cached): string
    {
        return self::getContainer()->get(Environment::class)->render('components/Atoms/Content.html.twig', [
            'parsed' => $cached,
            'rawContent' => $this->rawContent(),
            'renderMedia' => true,
        ]);
    }

    private function rawContent(): string
    {
        return 'Intro' . "\n\nnostr:" . $this->reference(self::HIGHLIGHT);
    }

    private function reference(string $id): string
    {
        return (string) Bech32::nevent(id: $id, kind: KindsEnum::HIGHLIGHTS->value);
    }

    private function event(string $id, int $kind, string $content, array $tags): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setKind($kind);
        $event->setPubkey(self::AUTHOR);
        $event->setContent($content);
        $event->setCreatedAt(123);
        $event->setTags($tags);
        $event->setSig(str_repeat('f', 128));
        return $event;
    }
}
