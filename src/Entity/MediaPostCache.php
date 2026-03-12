<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cached media post from relay queries (kinds 20, 21, 22).
 *
 * @see §12 of multimedia-manager spec
 */
#[ORM\Entity]
#[ORM\Table(name: 'media_post_cache')]
#[ORM\Index(name: 'idx_post_pubkey', columns: ['pubkey'])]
#[ORM\Index(name: 'idx_post_kind', columns: ['kind'])]
class MediaPostCache
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $eventId = '';

    #[ORM\Column(length: 64)]
    private string $pubkey = '';

    #[ORM\Column(type: Types::SMALLINT)]
    private int $kind = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $content = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $primaryUrl = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $primaryHash = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $previewUrl = null;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $duration = null;

    #[ORM\Column(type: 'jsonb', nullable: true)]
    private ?array $tagsJson = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $createdAt = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $lastSeenAt = null;

    public function getEventId(): string { return $this->eventId; }
    public function setEventId(string $v): self { $this->eventId = $v; return $this; }
    public function getPubkey(): string { return $this->pubkey; }
    public function setPubkey(string $v): self { $this->pubkey = $v; return $this; }
    public function getKind(): int { return $this->kind; }
    public function setKind(int $v): self { $this->kind = $v; return $this; }
    public function getTitle(): ?string { return $this->title; }
    public function setTitle(?string $v): self { $this->title = $v; return $this; }
    public function getContent(): ?string { return $this->content; }
    public function setContent(?string $v): self { $this->content = $v; return $this; }
    public function getPrimaryUrl(): ?string { return $this->primaryUrl; }
    public function setPrimaryUrl(?string $v): self { $this->primaryUrl = $v; return $this; }
    public function getPrimaryHash(): ?string { return $this->primaryHash; }
    public function setPrimaryHash(?string $v): self { $this->primaryHash = $v; return $this; }
    public function getPreviewUrl(): ?string { return $this->previewUrl; }
    public function setPreviewUrl(?string $v): self { $this->previewUrl = $v; return $this; }
    public function getDuration(): ?float { return $this->duration; }
    public function setDuration(?float $v): self { $this->duration = $v; return $this; }
    public function getTagsJson(): ?array { return $this->tagsJson; }
    public function setTagsJson(?array $v): self { $this->tagsJson = $v; return $this; }
    public function getCreatedAt(): ?int { return $this->createdAt; }
    public function setCreatedAt(?int $v): self { $this->createdAt = $v; return $this; }
    public function getLastSeenAt(): ?int { return $this->lastSeenAt; }
    public function setLastSeenAt(?int $v): self { $this->lastSeenAt = $v; return $this; }
}

