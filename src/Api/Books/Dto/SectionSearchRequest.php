<?php

declare(strict_types=1);

namespace App\Api\Books\Dto;

use App\Api\Books\Http\ApiException;

final class SectionSearchRequest
{
    private function __construct(
        public readonly string $q,
        public readonly int $limit,
        public readonly bool $quoted,
    ) {
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $unknown = array_diff(array_keys($input), ['q', 'limit']);
        if ($unknown !== []) {
            throw new ApiException(400, array_map(
                fn (string $field): string => sprintf('Unknown search property "%s"', $field),
                $unknown,
            ));
        }
        if (!isset($input['q']) || !is_string($input['q'])) {
            throw new ApiException(400, ['q is required and must be a string']);
        }

        $q = trim($input['q']);
        $quoted = str_starts_with($q, '"') || str_ends_with($q, '"');
        if ($quoted) {
            if (!str_starts_with($q, '"') || !str_ends_with($q, '"') || strlen($q) < 3) {
                throw new ApiException(400, ['Quoted q must have matching quotation marks']);
            }
            $q = trim(substr($q, 1, -1));
        }
        if (strlen($q) < 4) {
            throw new ApiException(400, ['q must contain at least 4 characters']);
        }
        if (strlen($q) > 160) {
            throw new ApiException(400, ['q must not exceed 160 characters']);
        }

        $limit = $input['limit'] ?? 25;
        if (!is_int($limit) && !(is_string($limit) && preg_match('/^\d+$/', $limit) === 1)) {
            throw new ApiException(400, ['limit must be an integer']);
        }
        $limit = (int) $limit;
        if ($limit < 1 || $limit > 100) {
            throw new ApiException(400, ['limit must be between 1 and 100']);
        }

        return new self($q, $limit, $quoted);
    }
}
