<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Admin;

use DecentNewsroom\UnfoldBundle\Admin\AboutIndexMutation;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use PHPUnit\Framework\TestCase;

final class AboutIndexMutationTest extends TestCase
{
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const FIRST = '30023:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb:first';
    private const SECOND = '30023:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc:second';
    private const THIRD = '30023:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd:third';

    public function testReplacingSoleRootArticlePreservesCategoriesContentAndUnrelatedTagOrder(): void
    {
        $original = [
            ['d', 'edition'],
            ['title', 'Magazine'],
            ['a', '30040:' . self::OWNER . ':culture'],
            ['a', self::FIRST, 'wss://relay.example'],
            ['p', self::OWNER],
            ['description', 'A publication'],
        ];
        $root = $this->root($original);

        self::assertSame(self::FIRST, AboutIndexMutation::currentCoordinate($root, null));
        self::assertSame([
            ['d', 'edition'],
            ['title', 'Magazine'],
            ['a', '30040:' . self::OWNER . ':culture'],
            ['p', self::OWNER],
            ['description', 'A publication'],
            ['a', self::SECOND],
        ], AboutIndexMutation::replace($root, null, self::SECOND));
        self::assertSame('Unchanged content', $root->content);
    }

    public function testClearRemovesStoredReferenceAndSoleInferredFallback(): void
    {
        $root = $this->root([
            ['d', 'edition'],
            ['a', self::FIRST],
            ['a', '30040:' . self::OWNER . ':culture'],
        ]);

        self::assertSame([
            ['d', 'edition'],
            ['a', '30040:' . self::OWNER . ':culture'],
        ], AboutIndexMutation::replace($root, self::THIRD, null));
    }

    public function testMultipleRootArticlesArePreservedUnlessExplicitlySelected(): void
    {
        $root = $this->root([
            ['d', 'edition'],
            ['a', self::FIRST],
            ['a', self::SECOND, 'wss://relay.example'],
            ['title', 'Magazine'],
        ]);

        self::assertNull(AboutIndexMutation::currentCoordinate($root, null));
        self::assertSame([
            ['d', 'edition'],
            ['a', self::FIRST],
            ['a', self::SECOND, 'wss://relay.example'],
            ['title', 'Magazine'],
            ['a', self::THIRD],
        ], AboutIndexMutation::replace($root, null, self::THIRD));
        self::assertSame([
            ['d', 'edition'],
            ['a', self::FIRST],
            ['a', self::SECOND, 'wss://relay.example'],
            ['title', 'Magazine'],
        ], AboutIndexMutation::replace($root, self::THIRD, self::SECOND));
    }

    public function testExistingSelectedReferenceKeepsItsRelayHintAndPosition(): void
    {
        $original = [
            ['d', 'edition'],
            ['a', self::SECOND, 'wss://relay.example'],
            ['a', self::FIRST],
        ];
        $root = $this->root($original);

        self::assertSame([
            ['d', 'edition'],
            ['a', self::SECOND, 'wss://relay.example'],
        ], AboutIndexMutation::replace($root, self::FIRST, self::SECOND));
    }

    /** @param list<list<string>> $tags */
    private function root(array $tags): NostrEvent
    {
        return new NostrEvent(
            str_repeat('e', 64),
            self::OWNER,
            30040,
            'Unchanged content',
            $tags,
            123,
            str_repeat('f', 128),
        );
    }
}