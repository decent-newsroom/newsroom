<?php

namespace App\Message;

class FetchCommentsMessage
{
    private string $coordinate;

    public function __construct(string $coordinate)
    {
        $this->coordinate = $coordinate;
    }

    public function getCoordinate(): string
    {
        return $this->coordinate;
    }
}

