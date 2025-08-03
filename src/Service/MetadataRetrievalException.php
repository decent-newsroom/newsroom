<?php

namespace App\Service;

class MetadataRetrievalException extends \Exception
{
    public function __construct(string $message = "Failed to retrieve metadata", int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
