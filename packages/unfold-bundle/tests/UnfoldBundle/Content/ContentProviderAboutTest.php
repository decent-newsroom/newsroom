<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Content;

use DecentNewsroom\UnfoldBundle\Cache\StaleWhileRevalidateCache;
use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Content\CategoryData;
use DecentNewsroom\UnfoldBundle\Content\ContentProvider;
use DecentNewsroom\UnfoldBundle\Contract\EventReadGatewayInterface;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use DecentNewsroom\UnfoldBundle\Contract\PublicationTreeLookupInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ContentProviderAboutTest extends TestCase
{
    public function testRootArticleIsUsedOnlyWhenExactlyOneDistinctReferenceExists(): void
    {
        $author = str_repeat('a', 64);
        $coordinate = '30023:' . $author . ':about';
        $event = $this->article($author, 'about');
        $gateway = $this->createMock(EventReadGatewayInterface::class);
        $gateway->expects(self::once())->method('findByCoordinate')->with($coordinate, [])->willReturn($event);
        $provider = $this->provider($gateway);

        self::assertNull($provider->getAboutArticle($this->site([])));
        self::assertSame('About article', $provider->getAboutArticle($this->site([$coordinate, $coordinate]))?->title);
        self::assertNull($provider->getAboutArticle($this->site([$coordinate, '30023:' . $author . ':other'])));
    }

    public function testSelectedArticleTakesPrecedenceOverSingleRootArticle(): void
    {
        $author = str_repeat('b', 64);
        $selected = '30023:' . $author . ':chosen';
        $gateway = $this->createMock(EventReadGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('findByCoordinate')
            ->with($selected, ['wss://relay.example'])
            ->willReturn($this->article($author, 'chosen'));

        $site = new SiteConfig(
            '30040:' . $author . ':root',
            'Magazine',
            '',
            null,
            [],
            $author,
            rootArticleCoordinates: ['30023:' . $author . ':root-about'],
            aboutArticleCoordinate: $selected,
            aboutRelayHints: ['wss://relay.example'],
        );

        self::assertSame('chosen', $this->provider($gateway)->getAboutArticle($site)?->slug);
    }

    public function testSelectedArticleUsesRelayHintsAndUnavailableSelectionDoesNotFallBackToRoot(): void
    {
        $author = str_repeat('b', 64);
        $selected = '30023:' . $author . ':chosen';
        $gateway = $this->createMock(EventReadGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('findByCoordinate')
            ->with($selected, ['wss://relay.example'])
            ->willReturn(null);

        $site = new SiteConfig(
            '30040:' . $author . ':root',
            'Magazine',
            'Description',
            null,
            [],
            $author,
            rootArticleCoordinates: ['30023:' . $author . ':root-about'],
            aboutArticleCoordinate: $selected,
            aboutRelayHints: ['wss://relay.example'],
        );

        self::assertNull($this->provider($gateway)->getAboutArticle($site));
    }

    public function testSelectedLookupRejectsAReplyWithTheWrongArticleIdentity(): void
    {
        $author = str_repeat('c', 64);
        $coordinate = '30023:' . $author . ':chosen';
        $gateway = $this->createMock(EventReadGatewayInterface::class);
        $gateway->method('findByCoordinate')->willReturn($this->article($author, 'different'));

        $site = new SiteConfig('30040:' . $author . ':root', 'Magazine', '', null, [], $author, aboutArticleCoordinate: $coordinate);
        self::assertNull($this->provider($gateway)->getAboutArticle($site));
    }

    public function testCategoryArticleAuthorsFollowReferenceOrderAndDeduplicate(): void
    {
        $first = str_repeat('a', 64);
        $second = str_repeat('b', 64);
        $third = str_repeat('c', 64);
        $coords = [
            '30023:' . $first . ':one',
            '30023:' . $second . ':two',
            '30023:' . $third . ':three',
        ];
        $gateway = $this->createMock(EventReadGatewayInterface::class);
        $gateway->expects(self::never())->method('findByCoordinates');
        $tree = $this->createMock(PublicationTreeLookupInterface::class);
        $tree->expects(self::exactly(2))->method('findChildren')->willReturnCallback(
            fn(string $coordinate): array => match ($coordinate) {
                '30040:owner:one' => [$this->article($second, 'two'), $this->article($first, 'one')],
                '30040:owner:two' => [$this->article($third, 'three'), $this->article($first, 'one')],
                default => [],
            },
        );

        $categories = [
            new CategoryData('one', 'One', '30040:owner:one', articleCoordinates: [$coords[0], $coords[1]]),
            new CategoryData('two', 'Two', '30040:owner:two', articleCoordinates: [$coords[0], $coords[2]]),
        ];

        self::assertSame([$first, $second, $third], $this->provider($gateway, $tree)->getCategoryArticleAuthorPubkeys($categories));
    }

    private function site(array $rootArticles): SiteConfig
    {
        $owner = str_repeat('a', 64);
        return new SiteConfig('30040:' . $owner . ':root', 'Magazine', '', null, [], $owner, rootArticleCoordinates: $rootArticles);
    }

    private function article(string $pubkey, string $slug): NostrEvent
    {
        return new NostrEvent('id-' . $slug, $pubkey, 30023, 'Body', [['d', $slug], ['title', 'About article']], 1, 'sig');
    }

    private function provider(EventReadGatewayInterface $gateway, ?PublicationTreeLookupInterface $tree = null): ContentProvider
    {
        return new ContentProvider($gateway, new StaleWhileRevalidateCache(new ArrayAdapter(), new NullLogger()), new NullLogger(), $tree);
    }
}
