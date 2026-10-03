<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Interactions;

use DecentNewsroom\UnfoldBundle\Contract\Comment;
use DecentNewsroom\UnfoldBundle\Contract\ProfileMetadataProviderInterface;
use DecentNewsroom\UnfoldBundle\Theme\CommentContentRenderer;
use DecentNewsroom\UnfoldBundle\Theme\ContextBuilder;
use nostriphant\NIP19\Bech32;

final readonly class InteractionView
{
    public function __construct(
        private ProfileMetadataProviderInterface $profiles,
        private ContextBuilder $context,
        private string $platformBaseUrl = 'https://decentnewsroom.com',
    ) {}

    /** @param list<Comment> $comments @return list<array<string, mixed>> */
    public function comments(array $comments): array
    {
        $pubkeys = [];
        foreach ($comments as $comment) {
            $pubkeys[] = $comment->pubkey;
            $pubkeys = [...$pubkeys, ...CommentContentRenderer::profilePubkeys($comment->content)];
        }
        $metadata = $this->profiles->getMultipleMetadata(array_values(array_unique($pubkeys)));
        $result = [];
        foreach ($comments as $comment) {
            $profile = $metadata[$comment->pubkey] ?? null;
            $result[] = [
                'id' => $comment->id, 'kind' => $comment->kind, 'pubkey' => $comment->pubkey,
                'content_html' => CommentContentRenderer::render($comment->content, $metadata, $this->platformBaseUrl),
                'created_at' => $comment->createdAt, 'created_at_formatted' => date('Y-m-d H:i', $comment->createdAt),
                'author' => [
                    'name' => $profile?->displayName ?: $profile?->name ?: substr($comment->pubkey, 0, 12),
                    'pic' => $profile?->picture, 'pubkey' => $comment->pubkey,
                    'url' => preg_match('/^[a-f0-9]{64}$/D', $comment->pubkey) === 1
                        ? rtrim($this->platformBaseUrl, '/') . '/p/' . Bech32::npub($comment->pubkey) : null,
                ],
                'parent_id' => self::parentId($comment),
                'is_zap' => $comment->kind === 9735,
                ...($comment->kind === 9735 ? $this->context->zapContext($comment) : []),
            ];
        }
        return $result;
    }

    public static function parentId(Comment $comment): ?string
    {
        if ($comment->kind !== 1111) {
            return null;
        }
        $isReply = false;
        $parents = [];
        foreach ($comment->tags as $tag) {
            $isReply = $isReply || (($tag[0] ?? null) === 'k' && ($tag[1] ?? null) === '1111');
            if (($tag[0] ?? null) === 'e' && is_string($tag[1] ?? null)) {
                $parents[$tag[1]] = true;
            }
        }
        return $isReply && count($parents) === 1 ? array_key_first($parents) : null;
    }
}
