<?php

declare(strict_types=1);

namespace App\Api\Books\Dto;

use App\Api\Books\Http\ApiException;

final class PublicationRecommendationRequest
{
    private const ALLOWED = ['seed_event_id', 'exclude_ids', 'limit'];

    /** @param list<string> $excludeIds */
    private function __construct(
        public readonly string $seedEventId,
        public readonly array $excludeIds,
        public readonly int $limit,
    ) {
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $unknown = array_diff(array_keys($input), self::ALLOWED);
        if ($unknown !== []) {
            throw new ApiException(400, array_values(array_map(
                static fn (string|int $field): string => sprintf('Unknown recommendation property "%s"', $field),
                $unknown,
            )));
        }

        $seed = self::eventId($input['seed_event_id'] ?? null, 'seed_event_id');
        $exclusions = array_key_exists('exclude_ids', $input) ? $input['exclude_ids'] : [];
        if (!is_array($exclusions) || !array_is_list($exclusions)) {
            throw new ApiException(400, ['exclude_ids must be an array of event IDs']);
        }
        if (count($exclusions) > 100) {
            throw new ApiException(400, ['exclude_ids must not contain more than 100 event IDs']);
        }
        $ids = [];
        foreach ($exclusions as $id) {
            $ids[] = self::eventId($id, 'exclude_ids');
        }

        $limit = array_key_exists('limit', $input) ? $input['limit'] : 10;
        if (!is_int($limit) || $limit < 1 || $limit > 50) {
            throw new ApiException(400, ['limit must be an integer between 1 and 50']);
        }

        return new self($seed, array_values(array_unique($ids)), $limit);
    }

    private static function eventId(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/\A[0-9a-fA-F]{64}\z/', $value) !== 1) {
            throw new ApiException(400, [sprintf('%s must contain full 64-character hexadecimal event IDs', $field)]);
        }

        return strtolower($value);
    }
}
