<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * DTO for advanced search filters.
 * All properties are nullable — null means "no filter applied".
 */
class SearchFilters
{
    public function __construct(
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public ?string $author = null,
        public ?string $tags = null,
        public ?int $kind = null,
        public string $sortBy = 'relevance',
    ) {
    }

    /**
     * Returns true when at least one filter is active (beyond the default sort).
     */
    public function hasActiveFilters(): bool
    {
        return $this->dateFrom !== null
            || $this->dateTo !== null
            || ($this->author !== null && $this->author !== '')
            || ($this->tags !== null && $this->tags !== '')
            || $this->kind !== null
            || $this->sortBy !== 'relevance';
    }

    /**
     * Parse the comma-separated tags string into a clean array.
     *
     * @return string[]
     */
    public function getTagsArray(): array
    {
        if ($this->tags === null || trim($this->tags) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(
                fn(string $t) => strtolower(trim($t)),
                explode(',', $this->tags)
            ),
            fn(string $t) => $t !== ''
        ));
    }
}

