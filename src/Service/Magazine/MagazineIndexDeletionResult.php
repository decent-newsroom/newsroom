<?php

declare(strict_types=1);

namespace App\Service\Magazine;

final readonly class MagazineIndexDeletionResult
{
    public function __construct(
        public int $deletedIndexes,
        public int $deletedMagazineProjections,
    ) {
    }
}
