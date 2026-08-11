<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Model;

use DecentNewsroom\ExpressionBundle\Contract\EventInterface;

final class ArrayEvent implements EventInterface
{
    /** @param array<int, array<int, string>> $tags */
    public function __construct(
        private readonly string $id,
        private readonly int $kind,
        private readonly string $pubkey,
        private readonly string $content,
        private readonly int $createdAt,
        private readonly array $tags,
        private readonly string $sig = '',
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (int) ($data['kind'] ?? 0),
            (string) ($data['pubkey'] ?? ''),
            (string) ($data['content'] ?? ''),
            (int) ($data['created_at'] ?? 0),
            array_values(array_filter(
                (array) ($data['tags'] ?? []),
                static fn(mixed $tag): bool => is_array($tag),
            )),
            (string) ($data['sig'] ?? ''),
        );
    }

    public function getId(): string { return $this->id; }
    public function getKind(): int { return $this->kind; }
    public function getPubkey(): string { return $this->pubkey; }
    public function getContent(): string { return $this->content; }
    public function getCreatedAt(): int { return $this->createdAt; }
    public function getTags(): array { return $this->tags; }
    public function getSig(): string { return $this->sig; }
}
