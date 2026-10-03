<?php

declare(strict_types=1);

namespace App\Tests\Unit\Unfold;

use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Content\PostData;
use DecentNewsroom\UnfoldBundle\Contract\Comment;
use DecentNewsroom\UnfoldBundle\Contract\CommentProviderInterface;
use DecentNewsroom\UnfoldBundle\Contract\InteractionPage;
use DecentNewsroom\UnfoldBundle\Contract\InteractionReaderInterface;
use DecentNewsroom\UnfoldBundle\Contract\InteractionTarget;
use DecentNewsroom\UnfoldBundle\Contract\MarkdownConverterInterface;
use DecentNewsroom\UnfoldBundle\Contract\ProfileMetadata;
use DecentNewsroom\UnfoldBundle\Contract\ProfileMetadataProviderInterface;
use DecentNewsroom\UnfoldBundle\Interactions\InteractionView;
use DecentNewsroom\UnfoldBundle\Theme\ContextBuilder;
use nostriphant\NIP19\Bech32;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class InteractionViewTest extends TestCase
{
    public function testCommentsEscapeContentAndLinkToMainDomainNpubProfile(): void
    {
        $pubkey = str_repeat('c', 64);
        $profiles = $this->createMock(ProfileMetadataProviderInterface::class);
        $profiles->expects(self::once())->method('getMultipleMetadata')->with([$pubkey])
            ->willReturn([$pubkey => new ProfileMetadata(displayName: 'Reader')]);
        $view = new InteractionView($profiles, $this->createMock(ContextBuilder::class), 'https://platform.example/');
        $comment = new Comment(str_repeat('b', 64), 1111, $pubkey, '<script>bad</script>', 100, [
            ['k', '1111'], ['e', str_repeat('d', 64)],
        ]);

        $data = $view->comments([$comment])[0];

        self::assertSame('https://platform.example/p/' . Bech32::npub($pubkey), $data['author']['url']);
        self::assertStringNotContainsString('<script>', $data['content_html']);
        self::assertSame(str_repeat('d', 64), $data['parent_id']);
        self::assertFalse($data['is_zap']);
    }

    public function testZapAndConflictingParentTagsDoNotOfferReplyAncestry(): void
    {
        foreach ([9735, 1111] as $kind) {
            $comment = new Comment(str_repeat('b', 64), $kind, str_repeat('c', 64), '', 100, [
                ['k', '1111'], ['e', str_repeat('d', 64)], ['e', str_repeat('e', 64)],
            ]);
            self::assertNull(InteractionView::parentId($comment));
        }
    }

    public function testServerRenderedDiscussionUsesValidatedReaderAndTotalCount(): void
    {
        $publication = '30040:' . str_repeat('a', 64) . ':root';
        $post = new PostData('story', 'Story', '', 'Body', null, 100, str_repeat('a', 64),
            '30023:' . str_repeat('a', 64) . ':story');
        $target = new InteractionTarget($publication, $post);
        $comment = new Comment(str_repeat('b', 64), 1111, str_repeat('c', 64), 'Reply', 100, [
            ['k', '1111'], ['e', str_repeat('d', 64)],
        ]);
        $reader = $this->createMock(InteractionReaderInterface::class);
        $reader->expects(self::once())->method('target')->with($publication, $post->coordinate)->willReturn($target);
        $reader->expects(self::once())->method('thread')->with($target)->willReturn(new InteractionPage([$comment], 'next', 123));
        $legacy = $this->createMock(CommentProviderInterface::class);
        $legacy->expects(self::never())->method('findByCoordinate');
        $context = $this->builder($reader, $legacy)->buildPostContext(
            new SiteConfig($publication, 'Publication', '', null, [], str_repeat('a', 64)), [], $post,
        )['post'];

        self::assertSame(123, $context['comments_count']);
        self::assertSame(str_repeat('d', 64), $context['comments'][0]['parent_id']);
        self::assertSame('https://platform.example/p/' . Bech32::npub($comment->pubkey), $context['comments'][0]['author']['url']);
        self::assertArrayNotHasKey('csrf_token', $context['interactions']);
    }

    public function testRemovedMembershipCannotRenderLegacyDiscussion(): void
    {
        $publication = '30040:' . str_repeat('a', 64) . ':root';
        $reader = $this->createMock(InteractionReaderInterface::class);
        $reader->method('target')->willReturn(null);
        $legacy = $this->createMock(CommentProviderInterface::class);
        $legacy->expects(self::never())->method('findByCoordinate');
        $post = new PostData('story', 'Story', '', 'Body', null, 100, str_repeat('a', 64),
            '30023:' . str_repeat('a', 64) . ':story');
        $this->expectException(NotFoundHttpException::class);
        $this->builder($reader, $legacy)->buildPostContext(
            new SiteConfig($publication, 'Publication', '', null, [], str_repeat('a', 64)), [], $post,
        );
    }

    private function builder(InteractionReaderInterface $reader, CommentProviderInterface $legacy): ContextBuilder
    {
        $profiles = $this->createMock(ProfileMetadataProviderInterface::class);
        $profiles->method('getMetadata')->willReturn(new ProfileMetadata(name: 'Author'));
        $profiles->method('getMultipleMetadata')->willReturn([]);
        $converter = $this->createMock(MarkdownConverterInterface::class);
        $converter->method('convertToHTML')->willReturn('<p>Body</p>');
        return new ContextBuilder($converter, new ArrayAdapter(), $profiles, $legacy,
            platformBaseUrl: 'https://platform.example', interactionReader: $reader);
    }
}
