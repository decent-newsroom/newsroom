<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Theme;

use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Content\PostData;
use DecentNewsroom\UnfoldBundle\Contract\Comment;
use DecentNewsroom\UnfoldBundle\Contract\CommentProviderInterface;
use DecentNewsroom\UnfoldBundle\Contract\MarkdownConverterInterface;
use DecentNewsroom\UnfoldBundle\Contract\ProfileMetadata;
use DecentNewsroom\UnfoldBundle\Contract\ProfileMetadataProviderInterface;
use DecentNewsroom\UnfoldBundle\Theme\CommentContentRenderer;
use DecentNewsroom\UnfoldBundle\Theme\ContextBuilder;
use DecentNewsroom\UnfoldBundle\Theme\HandlebarsRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CommentMentionTest extends TestCase
{
    public function testNprofileInCommentRendersAsSafePlatformMention(): void
    {
        $nprofile = 'nprofile1qqst0mtgkp3du662ztj3l4fgts0purksu5fgek5n4vgmg9gt2hkn9lqppemhxue69uhkummn9ekx7mp0qythwumn8ghj7un9d3shjtnswf5k6ctv9ehx2ap0qythwumn8ghj7un9d3shjtnp0faxzmt09ehx2ap06fen87';
        $mentionedPubkey = 'b7ed68b062de6b4a12e51fd5285c1e1e0ed0e5128cda93ab11b4150b55ed32fc';
        $npub = 'npub1klkk3vrzme455yh9rl2jshq7rc8dpegj3ndf82c3ks2sk40dxt7qulx3vt';
        $authorPubkey = str_repeat('a', 64);
        $content = 'Thank You, nostr:' . $nprofile . ', This is enlightening. &#x20;<script>alert(1)</script>';

        $converter = $this->createMock(MarkdownConverterInterface::class);
        $converter->method('convertToHTML')->willReturn('<p>Article</p>');
        $profiles = $this->createMock(ProfileMetadataProviderInterface::class);
        $profiles->method('getMetadata')->willReturn(new ProfileMetadata());
        $profiles->expects(self::once())
            ->method('getMultipleMetadata')
            ->with(self::callback(static fn (array $pubkeys): bool => in_array($authorPubkey, $pubkeys, true) && in_array($mentionedPubkey, $pubkeys, true)))
            ->willReturn([
                $authorPubkey => new ProfileMetadata(name: 'Author'),
                $mentionedPubkey => new ProfileMetadata(displayName: 'Alice & Bob'),
            ]);
        $comments = $this->createMock(CommentProviderInterface::class);
        $comments->method('findByCoordinate')->willReturn([
            new Comment(str_repeat('b', 64), 1111, $authorPubkey, $content, 100),
        ]);

        $builder = new ContextBuilder($converter, new ArrayAdapter(), $profiles, $comments, null, 'https://dn.example');
        $site = new SiteConfig('naddr', 'Publication', '', null, [], $authorPubkey);
        $post = new PostData('story', 'Story', '', 'Article', null, 100, $authorPubkey, '30023:' . $authorPubkey . ':story');
        $context = $builder->buildPostContext($site, [], $post);
        $renderer = new HandlebarsRenderer(
            new NullLogger(),
            dirname(__DIR__, 3) . '/Resources/themes',
            sys_get_temp_dir() . '/unfold-comment-mention-' . uniqid('', true),
        );

        $html = $renderer->render('post', $context);

        self::assertStringContainsString(
            '<a href="https://dn.example/p/' . $npub . '" class="nostr-mention">@Alice &amp; Bob</a>,',
            $html,
        );
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('nostr:' . $nprofile, $html);
    }
    public function testMalformedLongNostrIdentifierWrapsWithoutLosingText(): void
    {
        $identifier = 'nostr:nprofile1' . str_repeat('b', 180);
        $html = CommentContentRenderer::render($identifier . ', okay', [], 'https://dn.example');

        self::assertStringContainsString('<wbr>', $html);
        self::assertStringNotContainsString('<a ', $html);
        self::assertSame($identifier . ', okay', strip_tags($html));
    }
}
