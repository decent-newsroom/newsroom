<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Domain\Nip19;

final readonly class DecodedNip19
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private Nip19Type $type,
        private array $data,
    ) {
    }

    public function type(): Nip19Type
    {
        return $this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->data;
    }
}

