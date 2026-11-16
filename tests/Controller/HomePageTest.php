<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\UserMetadata;
use App\Service\Nostr\NostrKeyService;
use App\Service\Reader\FeaturedWritersRecommendations;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

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

    public function testFeaturedPackCardsRenderInsideTheHomeStream(): void
    {
        $client = static::createClient();
        $keys = new NostrKeyService();
        $packNpub = $keys->convertPublicKeyToBech32(str_repeat('a', 64));
        $members = [];
        for ($i = 1; $i <= 8; ++$i) {
            $members[] = [
                'npub' => $keys->convertPublicKeyToBech32(str_pad(dechex($i), 64, '0', STR_PAD_LEFT)),
                'metadata' => new UserMetadata(displayName: 'Writer ' . $i . ($i === 1 ? ' <script>' : ''), about: 'A short biography'),
            ];
        }
        $recommendations = $this->getMockBuilder(FeaturedWritersRecommendations::class)
            ->disableOriginalConstructor()->onlyMethods(['load'])->getMock();
        $recommendations->expects(self::once())->method('load')->willReturn([
            'packNpub' => $packNpub,
            'packDtag' => 'featured',
            'personal' => false,
            'members' => $members,
        ]);
        static::getContainer()->set(FeaturedWritersRecommendations::class, $recommendations);

        $crawler = $client->request('GET', '/home/stream');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#home-stream-content .content-stream .featured-people[data-controller~="ui--carousel"]');
        self::assertSelectorCount(8, '.featured-people__card[data-turbo-frame="_top"]');
        self::assertSelectorTextContains('.featured-people', 'Writers to discover');
        self::assertSelectorNotExists('.featured-people script');
        self::assertSelectorExists('.featured-people__track[tabindex="0"]');
        self::assertSame('/follow-pack/' . $packNpub . '/featured', $crawler->filter('.featured-people__pack')->first()->attr('href'));
        self::assertSame('/p/' . $members[0]['npub'], $crawler->filter('.featured-people__card')->first()->attr('href'));
        $rows = $crawler->filter('.content-stream__entry')->count();
        $precedingRows = 0;
        $carousel = $crawler->filter('.featured-people')->first()->getNode(0);
        for ($node = $carousel->previousSibling; $node !== null; $node = $node->previousSibling) {
            if ($node instanceof \DOMElement && str_contains(' ' . $node->getAttribute('class') . ' ', ' content-stream__entry ')) {
                ++$precedingRows;
            }
        }
        self::assertSame(min(5, $rows), $precedingRows);
        self::assertLessThanOrEqual(20, $rows);
    }

    public function testAnEmptyStreamStillRendersSuggestions(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');
        $npub = (new NostrKeyService())->convertPublicKeyToBech32(str_repeat('a', 64));
        $member = ['npub' => $npub, 'metadata' => new UserMetadata(name: 'A writer')];
        $html = static::getContainer()->get(Environment::class)->render('content_stream/_items.html.twig', [
            'rows' => [],
            'feedBreaks' => [0 => [$member]],
            'featuredPeople' => ['packNpub' => $npub, 'packDtag' => 'featured', 'personal' => true],
        ]);
        $crawler = new Crawler($html);
        self::assertCount(1, $crawler->filter('.stream-empty'));
        self::assertCount(1, $crawler->filter('.featured-people__card'));
        self::assertStringContainsString('A writer', $crawler->filter('.featured-people')->text());
        self::assertStringContainsString('From your selected follow pack', $crawler->filter('.featured-people')->text());
    }

    public function testFollowPackLinksSupportSlashesInTheIdentifier(): void
    {
        $client = static::createClient();
        $npub = (new NostrKeyService())->convertPublicKeyToBech32(str_repeat('a', 64));
        $recommendations = $this->getMockBuilder(FeaturedWritersRecommendations::class)
            ->disableOriginalConstructor()->onlyMethods(['load'])->getMock();
        $recommendations->method('load')->willReturn([
            'packNpub' => $npub,
            'packDtag' => 'featured/writers',
            'personal' => false,
            'members' => [['npub' => $npub, 'metadata' => new UserMetadata(name: 'Writer')]],
        ]);
        static::getContainer()->set(FeaturedWritersRecommendations::class, $recommendations);
        $crawler = $client->request('GET', '/home/stream');
        self::assertResponseIsSuccessful();
        $url = $crawler->filter('.featured-people__pack')->attr('href');
        $route = static::getContainer()->get('router')->match(rawurldecode($url));
        self::assertSame('follow_pack_view', $route['_route']);
        self::assertSame('featured/writers', $route['dtag']);
    }
}
