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
        [$client, , $store] = $this->client();
        $client->request('GET', 'https://publication.localhost/admin/settings');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="about_article"]');
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        $client->request('POST', 'https://publication.localhost/admin/settings', [
            '_token' => $token, 'theme' => 'default', 'about_article' => $this->aboutCoordinate(),
        ]);
        self::assertResponseRedirects('/admin/settings', 303);
        self::assertCount(1, $store->saved);
        self::assertSame($this->aboutCoordinate(), $store->saved[0]->aboutArticleCoordinate);
        self::assertSame([], $store->saved[0]->aboutRelayHints);

        $client->request('GET', 'https://localhost/mag/root/admin/settings');
        self::assertResponseIsSuccessful();
        self::assertSame($this->aboutCoordinate(), $client->getCrawler()->filter('#publication-about-article')->attr('value'));
        $token = $client->getCrawler()->filter('input[name="_token"]')->attr('value');
        $client->request('POST', 'https://localhost/mag/root/admin/settings', [
            '_token' => $token, 'theme' => 'default', 'about_article' => '',
        ]);
        self::assertResponseRedirects('/mag/root/admin/settings', 303);
        self::assertCount(2, $store->saved);
        self::assertSame($store->saved[0]->coordinate, $store->saved[1]->coordinate);
        self::assertNull($store->saved[1]->aboutArticleCoordinate);
        self::assertSame([], $store->saved[1]->aboutRelayHints);
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
        $events = $this->createMock(EventReadGatewayInterface::class);
        $aboutCoordinate = $this->aboutCoordinate();
        $events->method('findByCoordinate')->willReturnCallback(static function (string $key) use ($metadataAvailable, $coordinate, $aboutCoordinate): ?NostrEvent {
            if ($key === $aboutCoordinate) {
                return new NostrEvent(str_repeat('f', 64), str_repeat('c', 64), 30023, 'About article body', [['d', 'about'], ['title', 'About this publication']], 124, '');
            }
            if ($key === '30023:' . str_repeat('c', 64) . ':mismatch') {
                return new NostrEvent(str_repeat('f', 64), str_repeat('c', 64), 30023, 'Wrong article', [['d', 'different']], 125, '');
            }
            return $metadataAvailable && $key === $coordinate
                ? new NostrEvent(str_repeat('e', 64), str_repeat('a', 64), 30040, '', [['d', 'root'], ['title', 'Fixture publication'], ['description', 'Fixture description'], ['a', $aboutCoordinate]], 123, '')
                : null;
        });
        $container->set(PublicationAdminIdentityInterface::class, $identity);
        $container->set(PublicationSettingsStoreInterface::class, $store);
        $container->set(SiteRegistryInterface::class, $sites);
        $container->set(EventReadGatewayInterface::class, $events);
        return [$client, $identity, $store];
    }
}

class PublicationAdminTestKernel extends \App\Kernel
{
    public function getCacheDir(): string { return '/tmp/newsroom-unfold-admin-tests-v2'; }
    public function getBuildDir(): string { return $this->getCacheDir(); }
}
