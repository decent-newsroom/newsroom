<?php

declare(strict_types=1);

namespace App\Api\Books\Http;

use Symfony\Component\HttpFoundation\Request;

final class RequestDecoder
{
    private const MAX_JSON_BYTES = 65_536;

    /** @return array<string, mixed> */
    public function jsonObject(Request $request): array
    {
        $body = $request->getContent();
        if ($body === '' || strlen($body) > self::MAX_JSON_BYTES) {
            throw new ApiException(400, ['Request body must be a JSON object']);
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException(400, ['Request body must be valid JSON']);
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ApiException(400, ['Request body must be a JSON object']);
        }

        return $decoded;
    }

    /**
     * Preserve repeated query keys. The canonical collection format is
     * `ids=<id>&ids=<id>` (and `%23d=<value>` for a tag), never comma lists.
     *
     * @return array<string, string|list<string>>
     */
    public function repeatedQuery(Request $request): array
    {
        $query = $request->getQueryString();
        if ($query === null || $query === '') {
            return [];
        }

        $values = [];
        foreach (explode('&', $query) as $part) {
            [$rawKey, $rawValue] = array_pad(explode('=', $part, 2), 2, '');
            $key = rawurldecode($rawKey);
            $value = rawurldecode($rawValue);
            if ($key === '') {
                throw new ApiException(400, ['Query parameter names must not be empty']);
            }
            if (isset($values[$key])) {
                $values[$key] = is_array($values[$key]) ? [...$values[$key], $value] : [$values[$key], $value];
            } else {
                $values[$key] = $value;
            }
        }

        return $values;
    }
}
