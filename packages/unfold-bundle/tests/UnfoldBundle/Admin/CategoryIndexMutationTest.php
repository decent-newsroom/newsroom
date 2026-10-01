<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Admin;

use DecentNewsroom\UnfoldBundle\Admin\CategoryIndexMutation;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use PHPUnit\Framework\TestCase;

final class CategoryIndexMutationTest extends TestCase
{
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CULTURE = '30040:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb:culture';

    public function testAddsAndRemovesOnlyTheRequestedCategoryReference(): void
    {
        $root = $this->root([['d', 'edition'], ['title', 'Edition'], ['a', self::CULTURE, 'wss://relay.example'], ['p', self::OWNER]]);
        $science = '30040:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc:science';

        self::assertSame([
            ['d', 'edition'], ['title', 'Edition'], ['a', self::CULTURE, 'wss://relay.example'], ['p', self::OWNER], ['a', $science, 'wss://science.example'],
        ], CategoryIndexMutation::add($root, $science, ['wss://science.example']));
        self::assertSame([
            ['d', 'edition'], ['title', 'Edition'], ['p', self::OWNER],
        ], CategoryIndexMutation::remove($root, self::CULTURE));
    }

    public function testRejectsDuplicateAndRootReferences(): void
    {
        $root = $this->root([['d', 'edition'], ['a', self::CULTURE]]);

        $this->expectException(\InvalidArgumentException::class);
        CategoryIndexMutation::add($root, self::CULTURE, []);
    }

    /** @param list<list<string>> $tags */
    private function root(array $tags): NostrEvent
    {
        return new NostrEvent(str_repeat('e', 64), self::OWNER, 30040, '', $tags, 1, str_repeat('f', 128));
    }
}
