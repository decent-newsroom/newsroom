<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

final readonly class Comment
{
    /**
     * @param list<list<string>> $tags
     */
    public function __construct(
        public string $id,
        public int $kind,
        public string $pubkey,
        public string $content,
        public int $createdAt,
        public array $tags = [],
    ) {
    }
}
