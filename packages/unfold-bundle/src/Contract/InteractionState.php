<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

final readonly class InteractionState
{
    public function __construct(
        public bool $liked,
        public bool $reposted,
        public int $likes,
        public int $reposts,
    ) {}

    /** @return array{liked: bool, reposted: bool, likes: int, reposts: int} */
    public function toArray(): array
    {
        return [
            'liked' => $this->liked,
            'reposted' => $this->reposted,
            'likes' => $this->likes,
            'reposts' => $this->reposts,
        ];
    }
}
