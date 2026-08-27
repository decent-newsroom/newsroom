<?php

declare(strict_types=1);

namespace App\Service\Magazine;

final readonly class MagazineStructure
{
    /**
     * @param array<int, array<mixed>> $categoryTags
     * @param string[] $chapterCoordinates
     * @param array<string, string[]> $chapterRelayHints
     */
    public function __construct(
        public array $categoryTags,
        public array $chapterCoordinates,
        public ?string $frontPageArticleCoordinate,
        public array $chapterRelayHints = [],
    ) {
    }
}