<?php

declare(strict_types=1);

namespace App\Api\Books\Dto;

use App\Api\Books\Http\ApiException;

final class Nip01Filter
{
    private const ALLOWED = ['ids', 'authors', 'kinds', 'since', 'until', 'limit'];

    /**
     * @param list<string> $ids
     * @param list<string> $authors
     * @param list<int> $kinds
     * @param array<string, list<string>> $tags
     */
    private function __construct(
        public readonly array $ids,
        public readonly array $authors,
        public readonly array $kinds,
        public readonly ?int $since,
        public readonly ?int $until,
        public readonly int $limit,
        public readonly array $tags,
    ) {
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(array $input, bool $requireRange = false): self
    {
        $details = [];
        foreach ($input as $key => $_) {
            if (!in_array($key, self::ALLOWED, true) && preg_match('/^#[A-Za-z]$/', (string) $key) !== 1) {
                $details[] = sprintf('Unknown filter property "%s"', $key);
            }
        }

        if (!array_key_exists('limit', $input)) {
            $details[] = 'limit is required';
        }
        if ($requireRange) {
            foreach (['since', 'until'] as $field) {
                if (!array_key_exists($field, $input)) {
                    $details[] = sprintf('%s is required', $field);
                }
            }
        }
        if ($details !== []) {
            throw new ApiException(400, $details);
        }

        $limit = self::integer($input['limit'], 'limit', 1, 100);
        $since = array_key_exists('since', $input) ? self::integer($input['since'], 'since') : null;
        $until = array_key_exists('until', $input) ? self::integer($input['until'], 'until') : null;
        if ($since !== null && $until !== null && $since > $until) {
            throw new ApiException(400, ['since must be less than or equal to until']);
        }

        $tags = [];
        foreach ($input as $key => $value) {
            if (preg_match('/^#[A-Za-z]$/', (string) $key) === 1) {
                $tags[$key] = self::stringList($value, $key, 1, 256);
            }
        }

        return new self(
            self::hexList($input['ids'] ?? [], 'ids'),
            self::hexList($input['authors'] ?? [], 'authors'),
            self::integerList($input['kinds'] ?? [], 'kinds'),
            $since,
            $until,
            $limit,
            $tags,
        );
    }

    /**
     * @param string|list<string> $value
     * @return list<string>
     */
    public static function fromQueryValue(string|array $value): array
    {
        return is_array($value) ? $value : [$value];
    }

    private static function integer(mixed $value, string $field, ?int $minimum = null, ?int $maximum = null): int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            throw new ApiException(400, [sprintf('%s must be an integer', $field)]);
        }

        $integer = (int) $value;
        if (($minimum !== null && $integer < $minimum) || ($maximum !== null && $integer > $maximum)) {
            throw new ApiException(400, [sprintf('%s must be between %d and %d', $field, $minimum, $maximum)]);
        }

        return $integer;
    }

    /** @return list<int> */
    private static function integerList(mixed $value, string $field): array
    {
        if (!is_array($value) || array_is_list($value) === false) {
            throw new ApiException(400, [sprintf('%s must be an array', $field)]);
        }

        return array_map(fn (mixed $item): int => self::integer($item, $field), $value);
    }

    /** @return list<string> */
    private static function hexList(mixed $value, string $field): array
    {
        $items = self::stringList($value, $field, 1, 64);
        foreach ($items as $item) {
            if (preg_match('/^[A-Fa-f0-9]{1,64}$/', $item) !== 1) {
                throw new ApiException(400, [sprintf('%s must contain hexadecimal strings', $field)]);
            }
        }

        return $items;
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $field, int $minimumLength, int $maximumLength): array
    {
        if (!is_array($value) || array_is_list($value) === false) {
            throw new ApiException(400, [sprintf('%s must be an array', $field)]);
        }
        if (count($value) > 100) {
            throw new ApiException(400, [sprintf('%s may contain at most 100 values', $field)]);
        }

        foreach ($value as $item) {
            if (!is_string($item) || strlen($item) < $minimumLength || strlen($item) > $maximumLength) {
                throw new ApiException(400, [sprintf('%s contains an invalid value', $field)]);
            }
        }

        return $value;
    }
}
