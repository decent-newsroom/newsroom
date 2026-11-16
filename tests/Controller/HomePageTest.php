<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomePageTest extends WebTestCase
{
    public function testGuestHomePageProvidesMobileNavigation(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('button.site-header__menu[aria-controls="leftNav"]');
        self::assertSelectorExists('.app-shell[data-controller~="ui--sidebar-toggle"] #leftNav.app-sidebar');
        self::assertSelectorCount(1, '#leftNav');
        self::assertSelectorExists('#home-stream-content[src="/home/stream?page=1"]');
        self::assertSelectorNotExists('.profile-tabs');
        self::assertSelectorTextContains('h1', 'Follow good ideas further.');
    }

    public function testDiscoverHasOnlyOneCanonicalHome(): void
    {
        $client = static::createClient();
        $client->request('GET', '/discover');
        self::assertResponseRedirects('/');
    }

    public function testHomePaginationRetainsItsPageWhenReloaded(): void
    {
        $client = static::createClient();
        $client->request('GET', '/?page=2');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#home-stream-content[src="/home/stream?page=2"]');
    }

    public function testPublicStreamRouteDoesNotResolveAsAVanityProfile(): void
    {
        $client = static::createClient();
        $client->request('GET', '/home/stream');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#home-stream-content .content-stream');
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('private'));
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
    }
}
