<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Domain\Event;

use DecentNewsroom\NostrKernelBundle\Domain\Identity\Pubkey;

final readonly class NostrEvent
{
    public function __construct(
        private EventKind $kind,
        private Pubkey $pubkey,
        private EventTags $tags,
        private string $content = '',
        private int $createdAt = 0,
        private ?EventId $id = null,
        private ?EventSignature $signature = null,
    ) {
    }

    public function kind(): EventKind
    {
        return $this->kind;
    }

    public function pubkey(): Pubkey
    {
        return $this->pubkey;
    }

    public function tags(): EventTags
    {
        return $this->tags;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function createdAt(): int
    {
        return $this->createdAt;
    }

    public function id(): ?EventId
    {
        return $this->id;
    }

    public function signature(): ?EventSignature
    {
        return $this->signature;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'pubkey' => $this->pubkey->toHex(),
            'created_at' => $this->createdAt,
            'kind' => $this->kind->value(),
            'tags' => $this->tags->raw(),
            'content' => $this->content,
            'sig' => $this->signature?->toString(),
        ];
    }
}

