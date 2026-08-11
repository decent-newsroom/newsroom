<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Domain\Event;

final readonly class EventKind
{
    public function __construct(private int $value)
    {
    }

    public function value(): int
    {
        return $this->value;
    }

    public function isReplaceable(): bool
    {
        return $this->value === 0
            || $this->value === 3
            || ($this->value >= 10000 && $this->value < 20000);
    }

    public function isEphemeral(): bool
    {
        return $this->value >= 20000 && $this->value < 30000;
    }

    public function isAddressable(): bool
    {
        return $this->value >= 30000 && $this->value < 40000;
    }

    public function isLongFormArticle(): bool
    {
        return $this->value === 30023;
    }

    public function isRelayList(): bool
    {
        return $this->value === 10002;
    }

    public function isZapReceipt(): bool
    {
        return $this->value === 9735;
    }

    public function isHttpAuth(): bool
    {
        return $this->value === 27235;
    }
}

