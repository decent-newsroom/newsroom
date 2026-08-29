<?php

declare(strict_types=1);

namespace App\Api\Books\Dto;

use App\Api\Books\Http\ApiException;

final class PublicationSearchRequest
{
    private const ALLOWED = ['q', 'title', 'author', 'language', 'subject', 'd', 'identifier', 'limit'];

    private function __construct(
        public readonly ?string $q,
        public readonly ?string $title,
        public readonly ?string $author,
        public readonly ?string $language,
        public readonly ?string $subject,
        public readonly ?string $d,
        public readonly ?string $identifier,
        public readonly int $limit,
    ) {
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $unknown = array_diff(array_keys($input), self::ALLOWED);
        if ($unknown !== []) {
            throw new ApiException(400, array_map(
                fn (string $field): string => sprintf('Unknown search property "%s"', $field),
                $unknown,
            ));
        }

        return new self(
            self::optionalString($input, 'q', 160),
            self::optionalString($input, 'title', 160),
            self::optionalString($input, 'author', 160),
            self::optionalString($input, 'language', 32),
            self::optionalString($input, 'subject', 160),
            self::optionalString($input, 'd', 160),
            self::optionalString($input, 'identifier', 512),
            self::limit($input['limit'] ?? 25),
        );
    }

    /** @param array<string, mixed> $input */
    private static function optionalString(array $input, string $field, int $maximum): ?string
    {
        if (!array_key_exists($field, $input)) {
            return null;
        }
        if (!is_string($input[$field])) {
            throw new ApiException(400, [sprintf('%s must be a string', $field)]);
        }

        $value = trim($input[$field]);
        if ($value === '') {
            throw new ApiException(400, [sprintf('%s must not be empty', $field)]);
        }
        if (strlen($value) > $maximum) {
            throw new ApiException(400, [sprintf('%s must not exceed %d characters', $field, $maximum)]);
        }

        return $value;
    }

    private static function limit(mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^\d+$/', $value) === 1)) {
            throw new ApiException(400, ['limit must be an integer']);
        }
        $limit = (int) $value;
        if ($limit < 1 || $limit > 100) {
            throw new ApiException(400, ['limit must be between 1 and 100']);
        }

        return $limit;
    }
}
