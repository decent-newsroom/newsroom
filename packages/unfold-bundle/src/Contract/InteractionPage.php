<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

final readonly class InteractionPage
{
    /** @param list<Comment> $comments */
    public function __construct(
        public array $comments,
        public ?string $nextCursor,
        public int $count,
    ) {}
}
