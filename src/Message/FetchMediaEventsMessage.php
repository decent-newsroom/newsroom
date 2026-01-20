<?php

namespace App\Message;

class FetchMediaEventsMessage
{
    private array $hashtags;
    private array $kinds;

    public function __construct(array $hashtags, array $kinds = [20, 21, 22])
    {
        $this->hashtags = $hashtags;
        $this->kinds = $kinds;
    }

    public function getHashtags(): array
    {
        return $this->hashtags;
    }

    public function getKinds(): array
    {
        return $this->kinds;
    }
}
