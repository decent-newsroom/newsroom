<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Message to trigger background revalidation of profile cache.
 * Used for stale-while-revalidate pattern - serve stale data immediately,
 * then refresh in the background.
 */
class RevalidateProfileCacheMessage
{
    public function __construct(
        private readonly string $pubkey,
        private readonly string $tab,
        private readonly bool $isOwner = false,
    ) {}

    public function getPubkey(): string
    {
        return $this->pubkey;
    }

    public function getTab(): string
    {
        return $this->tab;
    }

    public function isOwner(): bool
    {
        return $this->isOwner;
    }
}

