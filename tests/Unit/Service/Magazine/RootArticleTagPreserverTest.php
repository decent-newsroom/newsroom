<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Magazine;

use App\Service\Magazine\RootArticleTagPreserver;
use PHPUnit\Framework\TestCase;

final class RootArticleTagPreserverTest extends TestCase
{
    public function testWizardKeepsDirectAboutReferenceAndItsRelayHintOnly(): void
    {
        $author = str_repeat('a', 64);
        $about = ['a', '30023:' . $author . ':about', 'wss://relay.example'];
        $category = ['a', '30040:' . $author . ':culture'];
        $rebuilt = [
            ['d', 'edition'],
            ['title', 'Updated title'],
            $category,
        ];
        $previous = [
            ['d', 'edition'],
            ['title', 'Old title'],
            $about,
            ['a', '30023:invalid:ignored'],
            ['a', '30040:' . $author . ':old-category'],
            ['p', $author],
        ];

        self::assertSame([
            ['d', 'edition'],
            ['title', 'Updated title'],
            $category,
            $about,
        ], RootArticleTagPreserver::append($rebuilt, $previous));
    }

    public function testExistingDirectReferenceIsNotDuplicated(): void
    {
        $about = ['a', '30023:' . str_repeat('b', 64) . ':about'];
        self::assertSame([$about], RootArticleTagPreserver::append([$about], [$about]));
    }
}