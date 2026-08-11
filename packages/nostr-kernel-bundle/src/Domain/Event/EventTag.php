<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Domain\Event;

final readonly class EventTag
{
    /**
     * @param list<mixed> $values
     * @param list<mixed> $raw
     */
    public function __construct(
        private string $name,
        private array $values,
        private array $raw,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<mixed>
     */
    public function values(): array
    {
        return $this->values;
    }

    public function firstValue(): ?string
    {
        $value = $this->values[0] ?? null;

        return \is_scalar($value) ? (string) $value : null;
    }

    /**
     * @return list<mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}

