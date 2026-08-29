<?php

declare(strict_types=1);

namespace App\Api\Books\Http;

final class ApiException extends \RuntimeException
{
    /** @param list<string> $details */
    public function __construct(
        private readonly int $status,
        private readonly array $details = [],
        string $message = 'Invalid request',
    ) {
        parent::__construct($message);
    }

    /** @return list<string> */
    public function details(): array
    {
        return $this->details;
    }

    public function status(): int
    {
        return $this->status;
    }
}
