<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class ZapSplit
{
    #[Assert\NotBlank(message: 'Recipient is required')]
    public string $recipient = '';

    #[Assert\Regex(
        pattern: '/^wss:\/\/.+/',
        message: 'Relay must be a valid WebSocket URL starting with wss://'
    )]
    public ?string $relay = null;

    #[Assert\PositiveOrZero(message: 'Weight must be 0 or greater')]
    public ?int $weight = null;

    /** Computed share percentage (0-100) */
    public ?float $sharePercent = null;

    public function __construct(
        string $recipient = '',
        ?string $relay = null,
        ?int $weight = null
    ) {
        $this->recipient = $recipient;
        $this->relay = $relay;
        $this->weight = $weight;
    }

    /**
     * Returns the recipient as hex pubkey.
     * If it's an npub, it gets converted to hex.
     */
    public function getRecipientHex(): string
    {
        // This will be handled by the service/utility
        return $this->recipient;
    }

    public function isNpub(): bool
    {
        return str_starts_with($this->recipient, 'npub1');
    }
}

