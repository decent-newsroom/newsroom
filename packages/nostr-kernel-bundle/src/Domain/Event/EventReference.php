<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Domain\Event;

final readonly class EventReference
{
    /**
     * @param list<mixed> $rawTag
     */
    public function __construct(
        private EventReferenceType $type,
        private string $value,
        private array $rawTag,
    ) {
    }

    public function type(): EventReferenceType
    {
        return $this->type;
    }

    public function value(): string
    {
        return $this->value;
    }

    /**
     * @return list<mixed>
     */
    public function rawTag(): array
    {
        return $this->rawTag;
    }
}

