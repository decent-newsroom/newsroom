<?php

declare(strict_types=1);

namespace App\Tests\Unit\Unfold;

use DecentNewsroom\UnfoldBundle\Content\PostData;
use DecentNewsroom\UnfoldBundle\Contract\Comment;
use DecentNewsroom\UnfoldBundle\Contract\InteractionTarget;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use DecentNewsroom\UnfoldBundle\Interactions\InteractionPolicy;
use PHPUnit\Framework\TestCase;

final class InteractionPolicyTest extends TestCase
{
    /** @dataProvider kinds */
    public function testAllKindsUseStableCommentRootAndRevisionParent(int $kind): void
    {
        $target = $this->target($kind);
        $policy = new InteractionPolicy();
        $event = $policy->prepare($target, str_repeat('c', 64), 'comment', 'Hello');
        self::assertSame(1111, $event['kind']);
        self::assertContains(['A', $target->post->coordinate, $target->relayHint], $event['tags']);
        self::assertContains(['K', (string) $kind], $event['tags']);
        self::assertContains(['a', $target->post->coordinate, $target->relayHint], $event['tags']);
        self::assertContains(['e', $target->post->eventId, $target->relayHint], $event['tags']);
        $policy->assertSignedIntent([...$event, 'id' => str_repeat('d', 64), 'sig' => str_repeat('e', 128)], $target, str_repeat('c', 64), 'comment');
    }

    public function testReplyKeepsRootButPointsToCommentParent(): void
    {
        $target = $this->target(30817);
        $parent = new Comment(str_repeat('d', 64), 1111, str_repeat('e', 64), 'Parent', time());
        $event = (new InteractionPolicy())->prepare($target, str_repeat('c', 64), 'reply', 'Reply', $parent);
        self::assertContains(['A', $target->post->coordinate, $target->relayHint], $event['tags']);
        self::assertContains(['e', $parent->id, $target->relayHint], $event['tags']);
        self::assertContains(['k', '1111'], $event['tags']);
        self::assertContains(['p', $parent->pubkey], $event['tags']);
        self::assertNotContains(['a', $target->post->coordinate, $target->relayHint], $event['tags']);
    }

    /** @dataProvider kinds */
    public function testLikeAndGenericRepostUseCorrectTargetKind(int $kind): void
    {
        $target = $this->target($kind);
        $policy = new InteractionPolicy();
        $like = $policy->prepare($target, str_repeat('c', 64), 'like');
        self::assertSame(7, $like['kind']);
        self::assertSame('+', $like['content']);
        self::assertContains(['k', (string) $kind], $like['tags']);
        $repost = $policy->prepare($target, str_repeat('c', 64), 'repost');
        self::assertSame(16, $repost['kind']);
        self::assertSame($target->post->eventId, json_decode($repost['content'], true)['id']);
        $policy->assertSignedIntent([...$repost, 'id' => str_repeat('d', 64), 'sig' => str_repeat('e', 128)], $target, str_repeat('c', 64), 'repost');
    }

    public function testProtectedRepostDoesNotEmbedOriginal(): void
    {
        $event = (new InteractionPolicy())->prepare($this->target(30023, [['-', '']]), str_repeat('c', 64), 'repost');
        self::assertSame('', $event['content']);
    }

    public function testAlteredRootCannotBePublished(): void
    {
        $policy = new InteractionPolicy();
        $target = $this->target(30818);
        $event = $policy->prepare($target, str_repeat('c', 64), 'comment', 'Hello');
        $event['tags'][0][1] = '30023:' . str_repeat('a', 64) . ':foreign';
        $this->expectException(\InvalidArgumentException::class);
        $policy->assertSignedIntent([...$event, 'id' => str_repeat('d', 64), 'sig' => str_repeat('e', 128)], $target, str_repeat('c', 64), 'comment');
    }

    public function testCommentByteLimitIncludesMultibyteText(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new InteractionPolicy())->prepare($this->target(30023), str_repeat('c', 64), 'comment', str_repeat("\xC3\xA9", 2001));
    }

    public function testMissingSignedSourceCannotBeReposted(): void
    {
        $target = $this->target(30023);
        $this->expectException(\InvalidArgumentException::class);
        (new InteractionPolicy())->prepare(new InteractionTarget($target->publicationCoordinate, $target->post), str_repeat('c', 64), 'repost');
    }

    public static function kinds(): iterable
    {
        foreach ([30023, 30041, 30818, 30817] as $kind) {
            yield [$kind];
        }
    }

    /** @param list<list<string>> $extraTags */
    private function target(int $kind, array $extraTags = []): InteractionTarget
    {
        $original = new NostrEvent(str_repeat('b', 64), str_repeat('a', 64), $kind, 'Public content', [['d', ' Name:with/slash '], ...$extraTags], time() - 10, str_repeat('f', 128));
        return new InteractionTarget('30040:' . str_repeat('a', 64) . ':root', PostData::fromEvent($original), $original, 'wss://relay.example');
    }
}
