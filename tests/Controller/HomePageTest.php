<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomePageTest extends WebTestCase
{
    public function testGuestHomePageProvidesMobileNavigation(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('button.site-header__menu[aria-controls="leftNav"]');
        self::assertSelectorExists(
            '.full-page__mobile-navigation[data-controller~="ui--sidebar-toggle"] #leftNav.app-sidebar'
        );
    }
}
