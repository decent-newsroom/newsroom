<?php

declare(strict_types=1);

namespace App\Tests\Unfold;

use App\EventListener\VisitTrackingListener;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;
use DecentNewsroom\UnfoldBundle\Contract\EventReadGatewayInterface;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use DecentNewsroom\UnfoldBundle\Contract\PublicationAdminIdentityInterface;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSettingsStoreInterface;
use DecentNewsroom\UnfoldBundle\Contract\PublicationSite;
use DecentNewsroom\UnfoldBundle\Contract\SiteRegistryInterface;
use DecentNewsroom\UnfoldBundle\Contract\SignedPublicationIndexPublisherInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class PublicationAdminFunctionalTest extends WebTestCase
{
    protected static function getKernelClass(): string { return PublicationAdminTestKernel::class; }

    public function testBothMountsRenderAndSaveAgainstSameCoordinate(): void
    {
        [$client, $identity, $store] = $this->client();
        $client->request('GET', 'https://publication.localhost/admin/settings');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/admin/settings"]');
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'), $client->getResponse()->headers->get('cache-control'));
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('private'));
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        $client->request('POST', 'https://publication.localhost/admin/settings', [
            '_token' => $token, 'theme' => 'default', 'coordinate' => '30040:' . str_repeat('b', 64) . ':other',
            'footer_links' => [['label' => 'Owner', 'url' => 'https://owner.example']],
        ]);
        self::assertResponseRedirects('/admin/settings', 303);
        self::assertCount(1, $store->saved);
        self::assertSame($this->coordinate(), $store->saved[0]->coordinate);
        self::assertSame([['label' => 'Owner', 'url' => 'https://owner.example']], $store->saved[0]->footerLinks);

        $client->request('GET', 'https://localhost/mag/root/admin/settings');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/mag/root/admin/settings"]');
        self::assertSame('https://owner.example', $client->getCrawler()->filter('#publication-link-url-0')->attr('value'));
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        $client->request('POST', 'https://localhost/mag/root/admin/settings', [
            '_token' => $token,
            'theme' => 'default',
            'footer_links' => [['label' => 'Owner', 'url' => 'https://owner.example']],
        ]);
        self::assertResponseRedirects('/mag/root/admin/settings', 303);
        self::assertCount(2, $store->saved);
        self::assertSame($store->saved[0]->coordinate, $store->saved[1]->coordinate);
        self::assertSame($store->saved[0]->footerLinks, $store->saved[1]->footerLinks);
        $client->request('GET', 'https://localhost/mag/root/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Fixture publication');
        self::assertSelectorExists('a[href="https://publication.localhost/rss.xml"]');
        $client->request('GET', 'https://publication.localhost/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Fixture publication');
    }

    public function testAboutArticleCanBeSelectedAndClearedAcrossBothAdminMounts(): void
    {
        [$client, , $store, $rootState] = $this->client();
        $client->request('GET', 'https://publication.localhost/admin/settings');
        self::assertResponseIsSuccessful();
        self::assertSame($this->aboutCoordinate(), $client->getCrawler()->filter('#publication-about-article')->attr('value'));
        self::assertSelectorTextContains('.unfold-admin-about-title', 'About this publication');
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');

        // A normal form POST cannot silently change the signed root index.
        $client->request('POST', 'https://publication.localhost/admin/settings', [
            '_token' => $token, 'theme' => 'default', 'about_article' => '',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $store->saved);

        $replacement = $this->replacementAboutCoordinate();
        $client->jsonRequest('POST', 'https://publication.localhost/admin/settings/about/prepare', [
            '_token' => $token, 'about_article' => $replacement,
        ]);
        self::assertResponseIsSuccessful();
        $prepared = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(str_repeat('e', 64), $prepared['base_event_id']);
        self::assertContains(['a', $replacement], $prepared['event']['tags']);
        self::assertNotContains(['a', $this->aboutCoordinate()], $prepared['event']['tags']);
        $signed = $prepared['event'] + ['id' => str_repeat('1', 64), 'sig' => str_repeat('2', 128)];
        $client->jsonRequest('POST', 'https://publication.localhost/admin/settings/about/commit', [
            '_token' => $token, 'about_article' => $replacement,
            'base_event_id' => $prepared['base_event_id'], 'event' => $signed,
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame($replacement, $store->saved[0]->aboutArticleCoordinate);
        self::assertContains(['a', $replacement], $rootState->event->tags);

        $client->request('GET', 'https://localhost/mag/root/admin/settings');
        self::assertResponseIsSuccessful();
        self::assertSame($replacement, $client->getCrawler()->filter('#publication-about-article')->attr('value'));
        self::assertSelectorTextContains('.unfold-admin-about-title', 'Replacement About');
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        $client->jsonRequest('POST', 'https://localhost/mag/root/admin/settings/about/prepare', [
            '_token' => $token, 'about_article' => '',
        ]);
        self::assertResponseIsSuccessful();
        $prepared = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotContains(['a', $replacement], $prepared['event']['tags']);
        $signed = $prepared['event'] + ['id' => str_repeat('3', 64), 'sig' => str_repeat('4', 128)];
        $client->jsonRequest('POST', 'https://localhost/mag/root/admin/settings/about/commit', [
            '_token' => $token, 'about_article' => '',
            'base_event_id' => $prepared['base_event_id'], 'event' => $signed,
        ]);
        self::assertResponseIsSuccessful();
        self::assertNull($store->saved[1]->aboutArticleCoordinate);
        self::assertNotContains(['a', $replacement], $rootState->event->tags);
        $client->request('GET', 'https://publication.localhost/admin/settings');
        self::assertResponseIsSuccessful();
        self::assertSame('', $client->getCrawler()->filter('#publication-about-article')->attr('value'));
    }

    public function testInvalidAboutArticleDoesNotWriteSettings(): void
    {
        [$client, , $store] = $this->client();
        $client->request('GET', 'https://publication.localhost/admin/settings');
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        $client->request('POST', 'https://publication.localhost/admin/settings', [
            '_token' => $token, 'theme' => 'default', 'about_article' => '30040:' . str_repeat('a', 64) . ':root',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $store->saved);
        $client->request('POST', 'https://publication.localhost/admin/settings', [
            '_token' => $token, 'theme' => 'default', 'about_article' => '30023:' . str_repeat('c', 64) . ':missing',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $store->saved);
        $client->request('POST', 'https://publication.localhost/admin/settings', [
            '_token' => $token, 'theme' => 'default', 'about_article' => '30023:' . str_repeat('c', 64) . ':mismatch',
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $store->saved);
    }

    public function testCsrfAndThemeFailuresDoNotWrite(): void
    {
        [$client, , $store] = $this->client();
        $client->request('POST', 'https://localhost/mag/root/admin/settings', ['theme' => 'default']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $store->saved);
        $client->jsonRequest('POST', 'https://publication.localhost/admin/settings/about/prepare', [
            'about_article' => '', '_token' => 'invalid',
        ]);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', 'https://localhost/mag/root/admin/settings/about/commit', [
            'about_article' => '', '_token' => 'invalid',
        ]);
        self::assertResponseStatusCodeSame(403);
        $client->request('GET', 'https://localhost/mag/root/admin/settings');
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        $client->request('POST', 'https://localhost/mag/root/admin/settings', ['_token' => $token, 'theme' => 'missing']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('option[value="missing"][selected]');
        self::assertSame([], $store->saved);
    }

    public function testOwnershipAndLoginAndPlatformRoutesRemainSeparate(): void
    {
        [$client, $identity] = $this->client();
        $identity->key = str_repeat('b', 64);
        $client->request('GET', 'https://publication.localhost/admin/settings');
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', 'https://publication.localhost/admin/settings/about/prepare', ['about_article' => '']);
        self::assertResponseStatusCodeSame(403);
        $client->jsonRequest('POST', 'https://localhost/mag/root/admin/settings/about/commit', ['about_article' => '']);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', 'https://localhost/mag/root/admin');
        self::assertResponseStatusCodeSame(404);
        $identity->key = null;
        $client->request('GET', 'https://publication.localhost/admin');
        self::assertResponseRedirects('https://localhost/login');
        $client->request('GET', 'https://localhost/admin');
        self::assertResponseStatusCodeSame(401);
        $client->request('GET', 'https://publication.localhost/admin/unfold');
        self::assertResponseStatusCodeSame(401);
    }

    public function testUnhostedPublicationHasSettingsWithoutPublicLinks(): void
    {
        [$client] = $this->client(hosted: false);
        $client->request('GET', 'https://localhost/mag/root/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.publication-admin-header a[target="_blank"]');
        $client->request('GET', 'https://localhost/mag/root/admin/settings');
        self::assertResponseIsSuccessful();
    }

    public function testHostedSettingsSurviveUnavailableMetadataAndStorageFailureRetainsInput(): void
    {
        [$client, , $store] = $this->client(metadataAvailable: false);
        $client->request('GET', 'https://publication.localhost/admin');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('p[role="status"]');
        $client->request('GET', 'https://publication.localhost/admin/settings');
        self::assertResponseIsSuccessful();
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        $store->fail = true;
        $client->request('POST', 'https://publication.localhost/admin/settings', ['_token' => $token, 'theme' => 'default']);
        self::assertResponseStatusCodeSame(503);
        self::assertSelectorExists('p[role="alert"]');
        self::assertSelectorExists('option[value="default"][selected]');
        self::assertSame([], $store->saved);
    }

    public function testNestedHostCannotUseRegisteredPublicationsAdmin(): void
    {
        [$client] = $this->client();
        $client->request('GET', 'https://publication.alias.localhost/admin/settings');
        self::assertResponseStatusCodeSame(404);
    }

    public function testHostedAboutRendersArticleAndAppearsInSitemap(): void
    {
        [$client] = $this->client();
        $client->request('GET', 'https://publication.localhost/about');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.about-article-content', 'About article body');
        self::assertSelectorExists('#about-magazine-people');
        self::assertSelectorExists('#about-featured-writers');
        self::assertSelectorExists('a[href="/about"]');

        $client->request('GET', 'https://publication.localhost/sitemap.xml');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('https://publication.localhost/about', $client->getResponse()->getContent());
    }

    public function testCompiledDiscoveryAndPublicRoutesStillResolveBeforeCatchAll(): void
    {
        [$client] = $this->client();
        $router = static::getContainer()->get('router');
        foreach (['/rss.xml' => 'unfold_rss', '/feed.xml' => 'unfold_feed', '/sitemap.xml' => 'unfold_sitemap', '/robots.txt' => 'unfold_robots', '/about' => 'unfold_site', '/' => 'unfold_site'] as $path => $name) {
            $request = Request::create('https://publication.localhost' . $path);
            $request->attributes->set('_unfold_site', new PublicationSite('publication', $this->coordinate()));
            self::assertSame($name, $router->matchRequest($request)['_route']);
        }
    }

    private function coordinate(): string { return '30040:' . str_repeat('a', 64) . ':root'; }
    private function aboutCoordinate(): string { return '30023:' . str_repeat('c', 64) . ':about'; }
    private function replacementAboutCoordinate(): string { return '30023:' . str_repeat('d', 64) . ':new-about'; }

    private function client(bool $hosted = true, bool $metadataAvailable = true): array
    {
        $client = static::createClient(['debug' => true]);
        $client->disableReboot();
        $container = static::getContainer();
        $container->set(VisitTrackingListener::class, $this->createMock(VisitTrackingListener::class));
        $coordinate = $this->coordinate();
        $identity = new class implements PublicationAdminIdentityInterface {
            public ?string $key;
            public function __construct() { $this->key = str_repeat('a', 64); }
            public function pubkey(): ?string { return $this->key; }
            public function loginUrl(Request $request): string { return 'https://localhost/login'; }
        };
        $store = new class implements PublicationSettingsStoreInterface {
            public array $saved = [];
            public bool $fail = false;
            public function find(string $coordinate): ?PublicationSettings {
                foreach (array_reverse($this->saved) as $settings) { if ($settings->coordinate === $coordinate) return $settings; }
                return null;
            }
            public function save(PublicationSettings $settings): void {
                if ($this->fail) throw new \RuntimeException('Storage unavailable');
                $this->saved[] = $settings;
            }
        };
        $sites = $this->createMock(SiteRegistryInterface::class);
        $sites->method('findBySubdomain')->willReturnCallback(fn (string $subdomain) => $hosted && $subdomain === 'publication' ? new PublicationSite('publication', $coordinate) : null);
        $sites->method('findByCoordinate')->willReturn($hosted ? new PublicationSite('publication', $coordinate) : null);
        $rootState = new \stdClass();
        $aboutCoordinate = $this->aboutCoordinate();
        $replacementCoordinate = $this->replacementAboutCoordinate();
        $rootState->event = new NostrEvent(
            str_repeat('e', 64), str_repeat('a', 64), 30040, '',
            [['d', 'root'], ['title', 'Fixture publication'], ['description', 'Fixture description'], ['a', $aboutCoordinate]],
            123, ''
        );
        $events = $this->createMock(EventReadGatewayInterface::class);
        $events->method('findByCoordinate')->willReturnCallback(static function (string $key) use ($metadataAvailable, $coordinate, $aboutCoordinate, $replacementCoordinate, $rootState): ?NostrEvent {
            if ($key === $aboutCoordinate) {
                return new NostrEvent(str_repeat('f', 64), str_repeat('c', 64), 30023, 'About article body', [['d', 'about'], ['title', 'About this publication']], 124, '');
            }
            if ($key === $replacementCoordinate) {
                return new NostrEvent(str_repeat('9', 64), str_repeat('d', 64), 30023, 'Replacement body', [['d', 'new-about'], ['title', 'Replacement About']], 126, '');
            }
            if ($key === '30023:' . str_repeat('c', 64) . ':mismatch') {
                return new NostrEvent(str_repeat('f', 64), str_repeat('c', 64), 30023, 'Wrong article', [['d', 'different']], 125, '');
            }
            return $metadataAvailable && $key === $coordinate ? $rootState->event : null;
        });
        $publisher = new class($store, $rootState) implements SignedPublicationIndexPublisherInterface {
            public function __construct(private object $store, private \stdClass $rootState) {}
            public function publish(array $signedEvent, string $publicationCoordinate, string $baseEventId, ?string $aboutCoordinate, array $relayHints, bool $updateAboutArticle = true): array {
                $this->rootState->event = new NostrEvent(
                    $signedEvent['id'], $signedEvent['pubkey'], $signedEvent['kind'],
                    $signedEvent['content'], $signedEvent['tags'], $signedEvent['created_at'], $signedEvent['sig']
                );
                if ($updateAboutArticle) {
                    $settings = $this->store->find($publicationCoordinate) ?? new PublicationSettings($publicationCoordinate);
                    $this->store->save($settings->withAboutArticle($aboutCoordinate, $relayHints));
                }
                return ['event_id' => $signedEvent['id'], 'published' => true, 'relay_results' => []];
            }
        };
        $container->set(PublicationAdminIdentityInterface::class, $identity);
        $container->set(PublicationSettingsStoreInterface::class, $store);
        $container->set(SiteRegistryInterface::class, $sites);
        $container->set(EventReadGatewayInterface::class, $events);
        $container->set(SignedPublicationIndexPublisherInterface::class, $publisher);
        return [$client, $identity, $store, $rootState];
    }
}

class PublicationAdminTestKernel extends \App\Kernel
{
    public function getCacheDir(): string { return '/tmp/newsroom-unfold-admin-tests-v2'; }
    public function getBuildDir(): string { return $this->getCacheDir(); }
}
