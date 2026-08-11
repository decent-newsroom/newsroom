<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Domain\Auth;

use DecentNewsroom\NostrKernelBundle\Domain\Identity\Pubkey;

final readonly class NostrHttpAuthResult
{
    /**
     * @param list<string> $errors
     */
    private function __construct(
        private bool $valid,
        private ?Pubkey $pubkey,
        private array $errors,
    ) {
    }

    public static function valid(Pubkey $pubkey): self
    {
        return new self(true, $pubkey, []);
    }

    /**
     * @param list<string> $errors
     */
    public static function invalid(array $errors): self
    {
        return new self(false, null, $errors);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function pubkey(): ?Pubkey
    {
        return $this->pubkey;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}

