<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Service\Admin\ElasticsearchAuthorBanList;
use PHPUnit\Framework\TestCase;

final class ElasticsearchAuthorBanListTest extends TestCase
{
    public function testUsesAuthorPubkeysNotDocumentIdsAndDeduplicates(): void
    {
        $hex = str_repeat('a', 64);
        $response = ['hits' => ['total' => ['value' => 10000, 'relation' => 'gte'], 'hits' => [
            ['_id' => '3121164', '_source' => ['pubkey' => $hex]],
            ['_id' => '3120232', '_source' => ['pubkey' => strtoupper($hex)]],
            ['_id' => '3112943', '_source' => ['pubkey' => str_repeat('b', 64)]],
        ]]];

        self::assertSame([$hex, str_repeat('b', 64)], (new ElasticsearchAuthorBanList())->extractPubkeys($response));
    }

    /** @dataProvider invalidResponseProvider */
    public function testInvalidExportIsRejectedBeforeAnyImport(array $response): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ElasticsearchAuthorBanList())->extractPubkeys($response);
    }

    public static function invalidResponseProvider(): array
    {
        return [
            'missing hits' => [[]],
            'no source' => [['hits' => ['hits' => [['_id' => '42']]]]],
            'bad key after valid hit' => [['hits' => ['hits' => [
                ['_source' => ['pubkey' => str_repeat('a', 64)]],
                ['_source' => ['pubkey' => 'not-a-key']],
            ]]]],
            'timeout' => [['timed_out' => true, 'hits' => ['hits' => []]]],
            'failed shard' => [['_shards' => ['failed' => 1], 'hits' => ['hits' => []]]],
        ];
    }

    public function testEmptyExportHasNoTargets(): void
    {
        self::assertSame([], (new ElasticsearchAuthorBanList())->extractPubkeys(['hits' => ['hits' => []]]));
    }
}
