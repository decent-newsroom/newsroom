<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util;

use App\Entity\Article;
use App\Util\BlockedArticleDomainPolicy;
use PHPUnit\Framework\TestCase;

final class BlockedArticleDomainPolicyTest extends TestCase
{
    /** @dataProvider linkProvider */
    public function testHostMatching(string $content, ?string $expected): void
    {
        $article = (new Article())->setContent($content);

        self::assertSame($expected, (new BlockedArticleDomainPolicy())->findBlockedDomain($article));
    }

    public static function linkProvider(): array
    {
        return [
            'markdown link' => ['[link](https://y5.pics)', 'y5.pics'],
            'markdown image' => ['![image](https://i.postimg.cc/abc/picture.png)', 'i.postimg.cc'],
            'html image' => ['<img src="https://i.postimg.cc/picture.png">', 'i.postimg.cc'],
            'html link' => ['<a href="https://go.cbrop.com/x">link</a>', 'go.cbrop.com'],
            'http' => ['http://y5.pics/path', 'y5.pics'],
            'case insensitive' => ['HTTPS://I.POSTIMG.CC/path', 'i.postimg.cc'],
            'subdomain' => ['https://cdn.y5.pics/path', 'y5.pics'],
            'protocol relative' => ['//go.cbrop.com/path', 'go.cbrop.com'],
            'bare link' => ['Visit y5.pics/path', 'y5.pics'],
            'bare host' => ['go.cbrop.com', 'go.cbrop.com'],
            'bare subdomain' => ['cdn.i.postimg.cc/path', 'i.postimg.cc'],
            'port' => ['https://go.cbrop.com:8443/path', 'go.cbrop.com'],
            'trailing host dot' => ['https://y5.pics./path', 'y5.pics'],
            'sentence punctuation' => ['Visit https://y5.pics.', 'y5.pics'],
            'bare punctuation' => ['Visit y5.pics.', 'y5.pics'],
            'credentials' => ['https://safe.test@y5.pics/path', 'y5.pics'],
            'safe host' => ['https://example.com/picture.jpg', null],
            'parent domain allowed' => ['https://postimg.cc/path', null],
            'cbrop parent allowed' => ['https://cbrop.com/path', null],
            'suffix lookalike' => ['https://noty5.pics/path', null],
            'appended domain' => ['https://y5.pics.example.com/path', null],
            'bare lookalike' => ['noty5.pics and y5.pics.example.com', null],
            'safe path' => ['https://example.com/y5.pics/path', null],
            'safe query' => ['https://example.com/?url=https://i.postimg.cc/picture.png', null],
            'safe fragment' => ['https://example.com/#go.cbrop.com', null],
            'safe host with credentials' => ['https://y5.pics@example.com/path', null],
            'email' => ['mail@y5.pics', null],
            'no link' => ['An ordinary article without links.', null],
        ];
    }

    public function testAllArticleSurfacesAndEventTags(): void
    {
        $articles = [
            (new Article())->setImage('https://i.postimg.cc/cover.png'),
            (new Article())->setSummary('See https://y5.pics/'),
            (new Article())->setTitle('Visit go.cbrop.com'),
        ];
        $tagged = new Article();
        $tagged->setRaw(['tags' => [['imeta', 'url https://i.postimg.cc/image.jpg']]]);
        $articles[] = $tagged;
        foreach ($articles as $article) {
            self::assertNotNull((new BlockedArticleDomainPolicy())->findBlockedDomain($article));
        }
    }

    public function testEmptyArticleAndNonStringTagValues(): void
    {
        $article = new Article();
        $article->setRaw(['tags' => [['image', null, 123], 'invalid']]);

        self::assertNull((new BlockedArticleDomainPolicy())->findBlockedDomain($article));
    }
}
