<?php

declare(strict_types=1);

namespace App\Tests\Unit\Unfold;

use App\Unfold\EventTagSnapshot;
use PHPUnit\Framework\TestCase;

final class EventTagSnapshotTest extends TestCase
{
    public function testPreservesOpaqueTagsExactly(): void
    {
        $tags = [['d', 'Topic'], [], ['flag'], ['a', 'reference', '', 'marker', 'extension'], ['unknown', '01'], ['unknown', '01']];

        self::assertSame($tags, EventTagSnapshot::validate($tags));
    }

    /** @dataProvider invalidTags */
    public function testRejectsRatherThanReconstructsMalformedTags(mixed $tags): void
    {
        $this->expectException(\UnexpectedValueException::class);
        EventTagSnapshot::validate($tags);
    }

    public static function invalidTags(): array
    {
        return [
            [null],
            [[['d', 123]]],
            [[['flag', false]]],
            [[['unknown', ['nested']]]],
            [['tags' => ['d', 'Topic']]],
            [[['name' => 'd', 'value' => 'Topic']]],
        ];
    }
}
