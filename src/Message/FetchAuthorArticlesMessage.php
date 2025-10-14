<?php

namespace App\Message;

class FetchAuthorArticlesMessage
{
    private string $pubkey;
    private int $since;

    public function __construct(string $pubkey, int $since)
    {
        $this->pubkey = $pubkey;
        $this->since = $since;
    }

    public function getPubkey(): string
    {
        return $this->pubkey;
    }

    public function getSince(): int
    {
        return $this->since;
    }
}
