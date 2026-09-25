<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Config;

use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use PHPUnit\Framework\TestCase;

final class SiteConfigAboutTest extends TestCase
{
    public function testRootReferencesSeparateCategoriesArticlesAndAuthors(): void
    {
        $person = str_repeat('A', 64);
        $event = new NostrEvent(
            id: 'root',
            pubkey: strtolower($person),
            kind: 30040,
            content: '',
            tags: [
                ['title', 'Magazine'],
                ['a', '30040:' . $person . ':culture'],
                ['a', '30023:' . $person . ':about'],
                ['a', '30023:' . $person . ':other'],
                ['p', $person],
                ['p', 'not-a-pubkey'],
            ],
            createdAt: 1,
            sig: 'sig',
        );

        $site = SiteConfig::fromEvent($event, '30040:' . strtolower($person) . ':root');

        self::assertSame(['30040:' . strtolower($person) . ':culture'], $site->categories);
        self::assertSame([
            '30023:' . strtolower($person) . ':about',
            '30023:' . strtolower($person) . ':other',
        ], $site->rootArticleCoordinates);
        self::assertSame([strtolower($person)], $site->authorPubkeys);
        self::assertSame($site->rootArticleCoordinates, $site->withTheme('default')->rootArticleCoordinates);
    }
}
