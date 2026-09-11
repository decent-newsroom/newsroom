<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

final readonly class ProfileMetadata
{
    /**
     * @param list<string> $lud16
     * @param list<string> $lud06
     */
    public function __construct(
        public ?string $name = null,
        public ?string $displayName = null,
        public ?string $picture = null,
        public array $lud16 = [],
        public array $lud06 = [],
    ) {
    }
}
