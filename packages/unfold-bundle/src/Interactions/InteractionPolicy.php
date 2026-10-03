<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Interactions;

use DecentNewsroom\UnfoldBundle\Contract\Comment;
use DecentNewsroom\UnfoldBundle\Contract\InteractionTarget;
use DecentNewsroom\UnfoldBundle\Theme\CommentContentRenderer;
use DecentNewsroom\UnfoldBundle\Config\ContentReference;

final class InteractionPolicy
{
    /** @return array{pubkey: string, kind: int, created_at: int, tags: list<list<string>>, content: string} */
    public function prepare(
        InteractionTarget $target,
        string $readerPubkey,
        string $action,
        string $content = '',
        ?Comment $parent = null,
        ?int $timestamp = null,
    ): array {
        $post = $target->post;
        if (!$post->isPublic() || preg_match('/^[a-f0-9]{64}$/D', $post->eventId) !== 1) {
            throw new \InvalidArgumentException('unfold_interactions.target_unavailable');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $readerPubkey) !== 1) {
            throw new \InvalidArgumentException('unfold_interactions.signer_mismatch');
        }
        $hint = $target->relayHint;
        $ref = static fn(string $name, string $value): array => $hint === null
            ? [$name, $value] : [$name, $value, $hint];
        $kind = match ($action) {
            'comment', 'reply' => 1111,
            'like' => 7,
            'repost' => 16,
            default => throw new \InvalidArgumentException('unfold_interactions.invalid'),
        };
        if ($kind === 1111) {
            if (trim($content) === '' || strlen($content) > 4000 || preg_match('//u', $content) !== 1) {
                throw new \InvalidArgumentException('unfold_interactions.invalid_comment');
            }
            $tags = [$ref('A', $post->coordinate), ['K', (string) $post->kind], ['P', $post->pubkey]];
            if ($action === 'reply') {
                if ($parent === null || $parent->kind !== 1111
                    || preg_match('/^[a-f0-9]{64}$/D', $parent->id) !== 1
                    || preg_match('/^[a-f0-9]{64}$/D', $parent->pubkey) !== 1) {
                    throw new \InvalidArgumentException('unfold_interactions.invalid_parent');
                }
                $tags = [...$tags, $ref('e', $parent->id), ['k', '1111'], ['p', $parent->pubkey]];
            } else {
                $tags = [...$tags, $ref('a', $post->coordinate), $ref('e', $post->eventId), ['k', (string) $post->kind], ['p', $post->pubkey]];
            }
            foreach (CommentContentRenderer::profilePubkeys($content) as $mentionedPubkey) {
                if (!in_array(['p', $mentionedPubkey], $tags, true)) {
                    $tags[] = ['p', $mentionedPubkey];
                }
            }
        } else {
            $tags = [$ref('e', $post->eventId), ['p', $post->pubkey], ['k', (string) $post->kind], $ref('a', $post->coordinate)];
            $content = '+';
            if ($kind === 16) {
                $original = $target->original;
                if ($original === null || $hint === null || $original->id !== $post->eventId
                    || $original->kind !== $post->kind || $original->pubkey !== $post->pubkey
                    || !ContentReference::fromInput($post->coordinate)->matches($original)
                    || ContentReference::isScoped($original)
                    || preg_match('/^[a-f0-9]{128}$/D', $original->sig) !== 1) {
                    throw new \InvalidArgumentException('unfold_interactions.repost_unavailable');
                }
                $protected = false;
                foreach ($original->tags as $tag) {
                    if (($tag[0] ?? null) === '-') {
                        $protected = true;
                    }
                }
                $content = $protected ? '' : json_encode([
                    'id' => $original->id, 'pubkey' => $original->pubkey,
                    'created_at' => $original->createdAt, 'kind' => $original->kind,
                    'tags' => $original->tags, 'content' => $original->content, 'sig' => $original->sig,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }
        return ['pubkey' => $readerPubkey, 'kind' => $kind, 'created_at' => $timestamp ?? time(), 'tags' => $tags, 'content' => $content];
    }

    /** @param array<string, mixed> $event */
    public function assertSignedIntent(
        array $event,
        InteractionTarget $target,
        string $readerPubkey,
        string $action,
        ?Comment $parent = null,
    ): void {
        if (!is_int($event['created_at'] ?? null) || $event['created_at'] > time() + 60
            || $event['created_at'] < time() - 86400 || !is_string($event['content'] ?? null)
            || !is_string($event['id'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $event['id']) !== 1
            || !is_string($event['sig'] ?? null) || preg_match('/^[a-f0-9]{128}$/D', $event['sig']) !== 1) {
            throw new \InvalidArgumentException('unfold_interactions.invalid');
        }
        if (($event['pubkey'] ?? null) !== $readerPubkey) {
            throw new \InvalidArgumentException('unfold_interactions.signer_mismatch');
        }
        $expected = $this->prepare($target, $readerPubkey, $action, $event['content'], $parent, $event['created_at']);
        foreach (['pubkey', 'kind', 'tags', 'content', 'created_at'] as $key) {
            if (($event[$key] ?? null) !== $expected[$key]) {
                throw new \InvalidArgumentException('unfold_interactions.stale_target');
            }
        }
    }
}
