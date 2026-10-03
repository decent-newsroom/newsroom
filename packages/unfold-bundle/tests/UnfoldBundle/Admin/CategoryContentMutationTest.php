<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\Admin;

use DecentNewsroom\UnfoldBundle\Admin\CategoryContentMutation;
use DecentNewsroom\UnfoldBundle\Config\ContentReference;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use PHPUnit\Framework\TestCase;

final class CategoryContentMutationTest extends TestCase
{
    /** @dataProvider kinds */
    public function testAddsForeignLeafWithoutChangingOpaqueTags(int $kind): void
    {
        $reference = ContentReference::fromInput($kind . ':' . str_repeat('B', 64) . ':Wiki:Ü/Case');
        $tags = [['d', 'category'], ['x', 'opaque', 'ordered'], ['title', 'Category'], ['a', '30024:' . str_repeat('c', 64) . ':draft', '', 'about']];
        $child = $this->event($tags);
        $updated = CategoryContentMutation::tags($child, $reference, 'add');
        self::assertSame([...$tags, ['a', $kind . ':' . str_repeat('b', 64) . ':Wiki:Ü/Case']], $updated);
        self::assertSame($updated, CategoryContentMutation::tags($this->event($updated), $reference, 'add'));
        self::assertSame($tags, CategoryContentMutation::tags($child, $reference, 'remove'));
    }

    public static function kinds(): array { return [[30023], [30041], [30818], [30817]]; }

    public function testRemovesUnresolvedCanonicalReferenceAndPreservesOrder(): void
    {
        $reference = ContentReference::fromInput('30817:' . str_repeat('b', 64) . ':Spec');
        $tags = [['d', 'category'], ['a', '30817:' . str_repeat('B', 64) . ':Spec', 'wss://relay.example', 'marker', 'opaque'], ['x', 'keep']];
        self::assertSame([['d', 'category'], ['x', 'keep']], CategoryContentMutation::tags($this->event($tags), $reference, 'remove'));
    }

    public function testRootMembershipUsesExactIdentifierAndCanonicalPubkey(): void
    {
        $owner = str_repeat('a', 64);
        $root = $this->event([['d', 'root'], ['a', '30040:' . strtoupper($owner) . ':Category']]);
        CategoryContentMutation::assertAttached($root, '30040:' . $owner . ':root', '30040:' . $owner . ':Category');
        $this->expectException(\InvalidArgumentException::class);
        CategoryContentMutation::assertAttached($root, '30040:' . $owner . ':root', '30040:' . $owner . ':category');
    }

    public function testRootCannotBeMutatedAsItsOwnChild(): void
    {
        $coordinate = '30040:' . str_repeat('a', 64) . ':root';
        $this->expectException(\InvalidArgumentException::class);
        CategoryContentMutation::assertAttached($this->event([['d', 'root'], ['a', $coordinate]]), $coordinate, $coordinate);
    }

    public function testSignedSourceIdentifierIsNotTrimmedIntoAnotherContentIdentity(): void
    {
        $coordinate = '30817:' . str_repeat('b', 64) . ':Spec ';
        $category = $this->event([['d', 'category'], ['a', $coordinate]]);
        $reference = ContentReference::fromInput(rtrim($coordinate));
        $references = CategoryContentMutation::references($category);
        self::assertSame($coordinate, $references[0]['reference']->coordinate);
        self::assertSame($category->tags, CategoryContentMutation::tags($category, $reference, 'remove'));
    }

    private function event(array $tags): NostrEvent
    {
        return new NostrEvent(str_repeat('e', 64), str_repeat('a', 64), 30040, 'opaque body', $tags, 123, '');
    }
}
