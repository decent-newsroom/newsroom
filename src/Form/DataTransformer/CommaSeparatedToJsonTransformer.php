<?php

namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;

class CommaSeparatedToJsonTransformer implements DataTransformerInterface
{

    /**
     *  Transforms model data (array|json|string|null) to a comma-separated string for the view.
     *  @inheritDoc
     */
    public function transform(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return '';
        }

        // Normalize to array
        if (is_string($value)) {
            // Try JSON first
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $array = $decoded;
            } else {
                // It's already a plain comma-separated string (legacy) — return as-is
                return $value;
            }
        } elseif (is_array($value)) {
            $array = $value;
        } else {
            // Unsupported type, return empty string
            return '';
        }

        // Clean up values
        $array = array_map(static fn($v) => is_string($v) ? trim($v) : $v, $array);
        $array = array_filter($array, static fn($v) => is_string($v) && $v !== '');

        return implode(',', $array);
    }

    /**
     * Transforms a comma-separated string from the view into an array for the model.
     * @inheritDoc
     */
    public function reverseTransform(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return [];
        }

        // If it's already an array (e.g., programmatic set), normalize it
        if (is_array($value)) {
            $array = array_map(static fn($v) => is_string($v) ? trim($v) : $v, $value);
            return array_values(array_filter($array, static fn($v) => is_string($v) && $v !== ''));
        }

        // If it looks like JSON, accept it for robustness
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $array = array_map(static fn($v) => is_string($v) ? trim($v) : $v, $decoded);
                return array_values(array_filter($array, static fn($v) => is_string($v) && $v !== ''));
            }

            // Fallback: parse comma-separated list
            $parts = array_map('trim', explode(',', $value));
            return array_values(array_filter($parts, static fn($v) => $v !== ''));
        }

        // Unknown type -> empty array
        return [];
    }
}
