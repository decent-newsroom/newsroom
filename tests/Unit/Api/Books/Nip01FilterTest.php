<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api\Books;

use App\Api\Books\Dto\Nip01Filter;
use App\Api\Books\Http\ApiException;
use PHPUnit\Framework\TestCase;

final class Nip01FilterTest extends TestCase
{
    public function testItPreservesSingleLetterTagCase(): void
    {
        $filter = Nip01Filter::fromArray([
            'limit' => 10,
            '#T' => ['The Republic'],
            '#t' => ['philosophy'],
        ]);

        self::assertSame(['The Republic'], $filter->tags['#T']);
        self::assertSame(['philosophy'], $filter->tags['#t']);
    }

    public function testItRejectsMultiLetterTagFilters(): void
    {
        $this->expectException(ApiException::class);

        Nip01Filter::fromArray(['limit' => 10, '#title' => ['The Republic']]);
    }

    public function testItRejectsAnInvertedTimeRange(): void
    {
        $this->expectException(ApiException::class);

        Nip01Filter::fromArray(['limit' => 10, 'since' => 20, 'until' => 10]);
    }

    public function testItRequiresTheGetRangeAndLimit(): void
    {
        $this->expectException(ApiException::class);

        Nip01Filter::fromArray(['limit' => 10], true);
    }
}
