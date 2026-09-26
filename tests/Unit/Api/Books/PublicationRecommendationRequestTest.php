<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api\Books;

use App\Api\Books\Dto\PublicationRecommendationRequest;
use App\Api\Books\Http\ApiException;
use PHPUnit\Framework\TestCase;

final class PublicationRecommendationRequestTest extends TestCase
{
    public function testDefaultsAndCaseInsensitiveExclusionDeduplication(): void
    {
        $request = PublicationRecommendationRequest::fromArray([
            'seed_event_id' => str_repeat('A', 64),
            'exclude_ids' => [str_repeat('B', 64), str_repeat('b', 64)],
        ]);

        self::assertSame(str_repeat('a', 64), $request->seedEventId);
        self::assertSame([str_repeat('b', 64)], $request->excludeIds);
        self::assertSame(10, $request->limit);
        self::assertSame([], PublicationRecommendationRequest::fromArray(['seed_event_id' => str_repeat('a', 64)])->excludeIds);
    }

    /** @dataProvider invalidRequests */
    public function testInvalidInputsReturnBadRequest(array $input): void
    {
        try {
            PublicationRecommendationRequest::fromArray($input);
            self::fail('Expected invalid recommendation request to fail');
        } catch (ApiException $exception) {
            self::assertSame(400, $exception->status());
            self::assertNotEmpty($exception->details());
        }
    }

    public static function invalidRequests(): iterable
    {
        $seed = ['seed_event_id' => str_repeat('a', 64)];
        yield 'missing seed' => [[]];
        yield 'short seed' => [['seed_event_id' => 'aaaa']];
        yield 'nonhex seed' => [['seed_event_id' => str_repeat('g', 64)]];
        yield 'trailing newline' => [['seed_event_id' => str_repeat('a', 64)."\n"]];
        yield 'array seed' => [['seed_event_id' => []]];
        yield 'unknown language' => [$seed + ['language' => 'en']];
        yield 'numeric property' => [$seed + [0 => 'value']];
        yield 'string exclusions' => [$seed + ['exclude_ids' => 'x']];
        yield 'null exclusions' => [$seed + ['exclude_ids' => null]];
        yield 'associative exclusions' => [$seed + ['exclude_ids' => ['key' => str_repeat('b', 64)]]];
        yield 'invalid exclusion' => [$seed + ['exclude_ids' => ['abcd']]];
        yield 'raw exclusion cap before deduplication' => [$seed + ['exclude_ids' => array_fill(0, 101, str_repeat('b', 64))]];
        foreach ([0, 51, -1, 1.5, '10', null, true] as $index => $limit) {
            yield 'invalid limit '.$index => [$seed + ['limit' => $limit]];
        }
    }

    public function testBoundaryValues(): void
    {
        foreach ([1, 50] as $limit) {
            $request = PublicationRecommendationRequest::fromArray([
                'seed_event_id' => str_repeat('a', 64),
                'exclude_ids' => array_map(static fn (int $id): string => sprintf('%064x', $id), range(1, 100)),
                'limit' => $limit,
            ]);
            self::assertSame($limit, $request->limit);
            self::assertCount(100, $request->excludeIds);
        }
    }
}
