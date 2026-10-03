<?php

declare(strict_types=1);

namespace App\Message;

final readonly class RefreshReaderInteractionsMessage
{
    public function __construct(public string $publicationCoordinate, public string $coordinate)
    {
    }
}
