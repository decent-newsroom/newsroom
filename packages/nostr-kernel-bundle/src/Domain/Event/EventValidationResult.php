<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Domain\Event;

final readonly class EventValidationResult
{
    /**
     * @param list<string> $errors
     */
    private function __construct(
        private bool $valid,
        private array $errors,
    ) {
    }

    public static function valid(): self
    {
        return new self(true, []);
    }

    /**
     * @param list<string> $errors
     */
    public static function invalid(array $errors): self
    {
        return new self(false, $errors);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}

