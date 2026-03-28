<?php

declare(strict_types=1);

namespace App\Message;

class FetchHighlightsMessage
{
    public function __construct(
        private readonly int $limit = 200
    ) {}

    public function getLimit(): int
    {
        return $this->limit;
    }
}
