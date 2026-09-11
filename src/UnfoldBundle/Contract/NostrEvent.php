<?php

declare(strict_types=1);

namespace App\UnfoldBundle\Contract;

final readonly class NostrEvent
{
    /**
     * @param list<list<string>> $tags
     */
    public function __construct(
        public string $id,
        public string $pubkey,
        public int $kind,
        public string $content,
        public array $tags,
        public int $createdAt,
        public string $sig,
    ) {
    }
}
