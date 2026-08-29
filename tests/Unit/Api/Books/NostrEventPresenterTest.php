<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api\Books;

use App\Api\Books\Presenter\NostrEventPresenter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NostrEventPresenterTest extends TestCase
{
    public function testItReturnsOnlyTheSwaggerEventFields(): void
    {
        $event = (new NostrEventPresenter(new NullLogger()))->present([
            'content' => 'hello',
            'created_at' => '42',
            'id' => str_repeat('a', 64),
            'kind' => '30040',
            'pubkey' => str_repeat('b', 64),
            'sig' => str_repeat('c', 128),
            'tags' => [['T', 'Book']],
            'tags_flat' => ['T' => 'Book'],
        ]);

        self::assertSame([
            'content',
            'created_at',
            'id',
            'kind',
            'pubkey',
            'sig',
            'tags',
        ], array_keys($event));
        self::assertSame(42, $event['created_at']);
    }

    public function testItSkipsMalformedTags(): void
    {
        $event = (new NostrEventPresenter(new NullLogger()))->present([
            'content' => 'hello',
            'created_at' => 42,
            'id' => str_repeat('a', 64),
            'kind' => 30040,
            'pubkey' => str_repeat('b', 64),
            'sig' => str_repeat('c', 128),
            'tags' => [['T', 3]],
        ]);

        self::assertNull($event);
    }
}
