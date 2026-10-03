<?php

declare(strict_types=1);

namespace App\Tests\Unfold;

use App\EventListener\VisitTrackingListener;
use App\Unfold\SignedCategoryIndexPublisher;
use App\Unfold\EventReadGatewayAdapter;
use DecentNewsroom\UnfoldBundle\Cache\SiteConfigCacheWarmer;
use DecentNewsroom\UnfoldBundle\Config\ContentReference;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;
use DecentNewsroom\UnfoldBundle\Contract\EventReadGatewayInterface;
use DecentNewsroom\UnfoldBundle\Contract\LocalEventReadGatewayInterface;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use DecentNewsroom\UnfoldBundle\Contract\PublicationAdminIdentityInterface;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSettingsStoreInterface;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSite;
use DecentNewsroom\UnfoldBundle\Contract\SiteRegistryInterface;
use DecentNewsroom\UnfoldBundle\Contract\SignedCategoryIndexPublisherInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;

final class CategoryContentFunctionalTest extends WebTestCase
{
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ROOT = '30040:' . self::OWNER . ':root';
    private const CHILD = '30040:' . self::OWNER . ':child';
    private const LEAF = '30817:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb:Spec';

    protected static function getKernelClass(): string { return CategoryContentTestKernel::class; }

    public function testBothMountsPrepareAndCommitChildWithoutRootOrSettingsWrites(): void
    {
        [$client, $state, $publisher, $store, , $cacheState] = $this->client();
        $originalRoot = $state->root;
        foreach (['https://publication.localhost/admin', 'https://localhost/mag/root/admin'] as $mount) {
            $client->request('GET', $mount . '/content/category?category=' . rawurlencode(self::CHILD));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('h1', 'Category');
            $token = $client->getCrawler()->filter('[data-nostr--nostr-unfold-category-csrf-token-value]')->attr('data-nostr--nostr-unfold-category-csrf-token-value');
            $payload = ['category' => self::CHILD, 'reference' => self::LEAF, 'action' => 'add', '_token' => $token];
            $client->jsonRequest('POST', $mount . '/content/category/prepare', $payload);
            self::assertResponseIsSuccessful();
            $prepared = json_decode($client->getResponse()->getContent(), true);
            self::assertContains(['d', 'child'], $prepared['event']['tags']);
            self::assertContains(['a', self::LEAF], $prepared['event']['tags']);
            self::assertSame('opaque child body', $prepared['event']['content']);
            $client->jsonRequest('POST', $mount . '/content/category/commit', [
                ...$payload, 'base_event_id' => $prepared['base_event_id'],
                'event' => [...$prepared['event'], 'id' => str_repeat('e', 64), 'sig' => str_repeat('f', 128)],
            ]);
            self::assertResponseIsSuccessful();
            $result = json_decode($client->getResponse()->getContent(), true);
            self::assertTrue($result['local_commit']);
            self::assertFalse($result['relay_complete']);
            self::assertTrue($result['retryable']);
            self::assertFalse($result['cache_refreshed']);
            self::assertSame(self::ROOT, $publisher->calls[array_key_last($publisher->calls)][1]);
            self::assertSame(self::CHILD, $publisher->calls[array_key_last($publisher->calls)][2]);
        }
        self::assertSame($originalRoot, $state->root);
        self::assertSame([], $store->saved);
        self::assertSame([self::CHILD, self::CHILD], $cacheState->invalidated);
    }

    public function testUnresolvedReferencesRemainVisibleAndRemovable(): void
    {
        [$client, $state] = $this->client();
        $state->child = $this->event(self::OWNER, 30040, [['d', 'child'], ['a', self::LEAF], ['x', 'keep']]);
        $state->leaf = null;
        $mount = 'https://publication.localhost/admin';
        $client->request('GET', $mount . '/content/category?category=' . rawurlencode(self::CHILD));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[data-reference="' . self::LEAF . '"]');
        $token = $client->getCrawler()->filter('[data-nostr--nostr-unfold-category-csrf-token-value]')->attr('data-nostr--nostr-unfold-category-csrf-token-value');
        $client->jsonRequest('POST', $mount . '/content/category/prepare', ['category' => self::CHILD, 'reference' => self::LEAF, 'action' => 'remove', '_token' => $token]);
        self::assertResponseIsSuccessful();
        $prepared = json_decode($client->getResponse()->getContent(), true);
        self::assertNotContains(['a', self::LEAF], $prepared['event']['tags']);
        self::assertContains(['x', 'keep'], $prepared['event']['tags']);
        $client->jsonRequest('POST', $mount . '/content/category/prepare', ['category' => self::CHILD, 'reference' => self::LEAF, 'action' => 'add', '_token' => $token]);
        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode($client->getResponse()->getContent(), true)['unchanged']);
    }

    public function testMissingAndScopedLeavesCannotBeNewlyAttached(): void
    {
        [$client, $state] = $this->client();
        $mount = 'https://publication.localhost/admin';
        $client->request('GET', $mount . '/content/category?category=' . rawurlencode(self::CHILD));
        $token = $client->getCrawler()->filter('[data-nostr--nostr-unfold-category-csrf-token-value]')->attr('data-nostr--nostr-unfold-category-csrf-token-value');
        $payload = ['category' => self::CHILD, 'reference' => self::LEAF, 'action' => 'add', '_token' => $token];
        $state->leaf = null;
        $client->jsonRequest('POST', $mount . '/content/category/prepare', $payload);
        self::assertResponseStatusCodeSame(422);
        $state->leaf = $this->event(str_repeat('b', 64), 30817, [['d', 'Spec'], ['s', 'private']]);
        $client->jsonRequest('POST', $mount . '/content/category/prepare', $payload);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('unfold_category.scoped', json_decode($client->getResponse()->getContent(), true)['error']);
    }

    public function testBothMountsLoadStoredInventoryWithoutRelayRequests(): void
    {
        [$client, $state] = $this->client();
        $state->child = $this->event(self::OWNER, 30040, [
            ['d', 'child'], ['title', 'Category'], ['a', self::LEAF, 'wss://unavailable.example'],
        ]);
        foreach (['https://publication.localhost/admin', 'https://localhost/mag/root/admin'] as $mount) {
            $client->request('GET', $mount . '/content/category?category=' . rawurlencode(self::CHILD));
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('.unfold-category__inventory h2', 'Specification');
            self::assertSelectorExists('form[data-reference="' . self::LEAF . '"]');
            $state->leaf = null;
            $client->request('GET', $mount . '/content/category?category=' . rawurlencode(self::CHILD));
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('form[data-reference="' . self::LEAF . '"]');
            $state->leaf = $this->event(str_repeat('b', 64), 30817, [['d', 'Spec'], ['title', 'Specification']]);
        }
        self::assertSame([], $state->networkLookups);
        self::assertSame([[self::LEAF], [self::LEAF], [self::LEAF], [self::LEAF]], $state->localBatches);
    }

    public function testMissingStoredCategoryDoesNotFallBackToRelays(): void
    {
        [$client, $state] = $this->client();
        $state->child = null;
        foreach (['https://publication.localhost/admin', 'https://localhost/mag/root/admin'] as $mount) {
            $client->request('GET', $mount . '/content/category?category=' . rawurlencode(self::CHILD));
            self::assertResponseStatusCodeSame(422);
        }
        self::assertSame([], $state->networkLookups);
        self::assertSame([], $state->localBatches);
    }

    public function testMissingStoredRootDoesNotFallBackToRelaysOnEitherMount(): void
    {
        [$client, $state] = $this->client();
        $state->root = null;
        $client->request('GET', 'https://publication.localhost/admin/content/category?category=' . rawurlencode(self::CHILD));
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', 'https://localhost/mag/root/admin/content/category?category=' . rawurlencode(self::CHILD));
        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $state->networkLookups);
        self::assertSame([], $state->localBatches);
    }

    public function testOversizedMutationBodiesAreRejectedBeforePublishing(): void
    {
        [$client, , $publisher] = $this->client();
        $body = json_encode(['content' => str_repeat('x', 1_000_001)], JSON_THROW_ON_ERROR);
        foreach (['prepare', 'commit'] as $action) {
            $client->request('POST', 'https://publication.localhost/admin/content/category/' . $action,
                [], [], ['CONTENT_TYPE' => 'application/json'], $body);
            self::assertResponseStatusCodeSame(422);
            self::assertSame('unfold_category.invalid', json_decode($client->getResponse()->getContent(), true)['error']);
        }
        self::assertSame([], $publisher->calls);
    }

    public function testForeignCategoryReadOnlyAndCsrfAndOwnerGuards(): void
    {
        [$client, $state, , , $identity] = $this->client();
        $foreign = '30040:' . str_repeat('b', 64) . ':child';
        $state->root = $this->event(self::OWNER, 30040, [['d', 'root'], ['a', $foreign]]);
        $state->coordinate = $foreign;
        $state->child = $this->event(str_repeat('b', 64), 30040, [['d', 'child']]);
        $client->request('GET', 'https://publication.localhost/admin/content/category?category=' . rawurlencode($foreign));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[data-mutation="add"]');
        $token = $client->getCrawler()->filter('[data-nostr--nostr-unfold-category-csrf-token-value]')->attr('data-nostr--nostr-unfold-category-csrf-token-value');
        $payload = ['category' => $foreign, 'reference' => self::LEAF, 'action' => 'add', '_token' => $token];
        $client->jsonRequest('POST', 'https://publication.localhost/admin/content/category/prepare', $payload);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', 'https://publication.localhost/admin/content/category/commit', [...$payload, '_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
        $identity->key = str_repeat('b', 64);
        $client->request('GET', 'https://publication.localhost/admin/content/category?category=' . rawurlencode($foreign));
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', 'https://publication.localhost/admin/content/category/commit', $payload);
        self::assertResponseStatusCodeSame(403);
    }

    private function event(string $pubkey, int $kind, array $tags): NostrEvent
    {
        return new NostrEvent(str_repeat('c', 64), $pubkey, $kind, 'opaque child body', $tags, 123, '');
    }

    private function client(): array
    {
        $client = static::createClient(['debug' => true]);
        $client->disableReboot();
        $container = static::getContainer();
        $container->set(VisitTrackingListener::class, $this->createMock(VisitTrackingListener::class));
        $cacheState = (object) ['invalidated' => []];
        $warmer = $this->createMock(SiteConfigCacheWarmer::class);
        $warmer->method('warmCategoryMutation')->willReturnCallback(static function (string $publication, string $category) use ($cacheState): bool {
            self::assertSame(self::ROOT, $publication);
            $cacheState->invalidated[] = $category;
            return false;
        });
        $container->set(SiteConfigCacheWarmer::class, $warmer);
        $identity = new class implements PublicationAdminIdentityInterface {
            public ?string $key;
            public function __construct() { $this->key = str_repeat('a', 64); }
            public function pubkey(): ?string { return $this->key; }
            public function loginUrl(Request $request): string { return 'https://localhost/login'; }
        };
        $store = new class implements PublicationSettingsStoreInterface {
            public array $saved = [];
            public function find(string $coordinate): ?PublicationSettings { return null; }
            public function save(PublicationSettings $settings): void { $this->saved[] = $settings; }
        };
        $sites = $this->createMock(SiteRegistryInterface::class);
        $site = new PublicationSite('publication', self::ROOT);
        $sites->method('findBySubdomain')->willReturnCallback(static fn ($subdomain) => $subdomain === 'publication' ? $site : null);
        $sites->method('findByCoordinate')->willReturn($site);
        $state = (object) [
            'root' => $this->event(self::OWNER, 30040, [['d', 'root'], ['a', self::CHILD]]),
            'child' => $this->event(self::OWNER, 30040, [['d', 'child'], ['title', 'Category'], ['x', 'keep']]),
            'leaf' => $this->event(str_repeat('b', 64), 30817, [['d', 'Spec'], ['title', 'Specification']]),
            'coordinate' => self::CHILD,
            'networkLookups' => [],
            'localBatches' => [],
        ];
        $events = $this->createMock(EventReadGatewayInterface::class);
        $events->method('findByCoordinate')->willReturnCallback(static function ($coordinate) use ($state) {
            $state->networkLookups[] = $coordinate;
            if ($coordinate !== self::LEAF) {
                throw new \LogicException('Index loading must use the wizard local lookup.');
            }
            return $state->leaf;
        });
        $events->expects(self::never())->method('findByCoordinates');
        $localEvents = $this->createMock(LocalEventReadGatewayInterface::class);
        $localEvents->method('findLocalByCoordinate')->willReturnCallback(static fn ($coordinate) => match ($coordinate) {
            self::ROOT => $state->root, $state->coordinate => $state->child, self::LEAF => $state->leaf, default => null,
        });
        $localEvents->method('findLocalByCoordinates')->willReturnCallback(static function (array $coordinates) use ($state): array {
            $state->localBatches[] = $coordinates;
            return $state->leaf !== null && in_array(self::LEAF, $coordinates, true) ? [self::LEAF => $state->leaf] : [];
        });
        $publisher = new class implements SignedCategoryIndexPublisherInterface {
            public array $calls = [];
            public function publish(array $signedEvent, string $publicationCoordinate, string $categoryCoordinate, string $baseEventId, ContentReference $reference, string $action): array {
                $this->calls[] = func_get_args();
                return ['event_id' => $signedEvent['id'], 'local_commit' => true, 'published' => false, 'relay_complete' => false, 'retryable' => true, 'relay_results' => []];
            }
        };
        $container->set(PublicationAdminIdentityInterface::class, $identity);
        $container->set(PublicationSettingsStoreInterface::class, $store);
        $container->set(SiteRegistryInterface::class, $sites);
        $container->set(EventReadGatewayInterface::class, $events);
        $container->set(LocalEventReadGatewayInterface::class, $localEvents);
        $container->set(SignedCategoryIndexPublisherInterface::class, $publisher);
        return [$client, $state, $publisher, $store, $identity, $cacheState];
    }
}

class CategoryContentTestKernel extends \App\Kernel
{
    public function getCacheDir(): string { return $this->getProjectDir() . '/var/category-content-tests-' . getmypid(); }
    public function getBuildDir(): string { return $this->getCacheDir(); }

    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->removeAlias(LocalEventReadGatewayInterface::class);
                $container->setDefinition(LocalEventReadGatewayInterface::class,
                    (clone $container->getDefinition(EventReadGatewayAdapter::class))->setPublic(true));
                $container->setAlias(SignedCategoryIndexPublisherInterface::class, SignedCategoryIndexPublisher::class)->setPublic(true);
                $container->getDefinition(SiteConfigCacheWarmer::class)->setPublic(true);
            }
        });
    }
}
