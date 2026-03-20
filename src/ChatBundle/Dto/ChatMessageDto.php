<?php

declare(strict_types=1);

namespace App\ChatBundle\Dto;

/**
 * Read-only DTO for a chat message (NIP-28 kind 42 event).
 */
class ChatMessageDto implements \JsonSerializable
{
    public function __construct(
        public readonly string $eventId,
        public readonly string $senderPubkey,
        public readonly string $senderDisplayName,
        public readonly string $content,
        public readonly int $createdAt,
        public readonly bool $isReply = false,
        public readonly ?string $replyToEventId = null,
        public readonly bool $isHidden = false,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'eventId' => $this->eventId,
            'senderPubkey' => $this->senderPubkey,
            'senderDisplayName' => $this->senderDisplayName,
            'content' => $this->content,
            'createdAt' => $this->createdAt,
            'isReply' => $this->isReply,
            'replyToEventId' => $this->replyToEventId,
        ];
    }
}

