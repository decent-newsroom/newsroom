<?php

declare(strict_types=1);

namespace App\ChatBundle\Dto;

/**
 * Read-only DTO for a chat group summary.
 */
class ChatGroupDto implements \JsonSerializable
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $status,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'status' => $this->status,
        ];
    }
}

