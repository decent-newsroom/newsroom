<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cached media asset from a provider (Blossom or NIP-96).
 *
 * @see §12 of multimedia-manager spec
 */
#[ORM\Entity]
#[ORM\Table(name: 'media_asset_cache')]
#[ORM\UniqueConstraint(name: 'uniq_asset_provider_hash', columns: ['provider_id', 'asset_hash'])]
#[ORM\Index(name: 'idx_asset_owner', columns: ['owner_pubkey'])]
class MediaAssetCache
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $providerId = '';

    #[ORM\Column(length: 64)]
    private string $ownerPubkey = '';

    #[ORM\Column(length: 64)]
    private string $assetHash = '';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $originalHash = null;

    #[ORM\Column(length: 512)]
    private string $url = '';

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $mime = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $size = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $dim = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $alt = null;

    #[ORM\Column(type: 'jsonb', nullable: true)]
    private ?array $metadataJson = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $uploadedAt = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $lastSeenAt = null;

    public function getId(): ?int { return $this->id; }
    public function getProviderId(): string { return $this->providerId; }
    public function setProviderId(string $v): self { $this->providerId = $v; return $this; }
    public function getOwnerPubkey(): string { return $this->ownerPubkey; }
    public function setOwnerPubkey(string $v): self { $this->ownerPubkey = $v; return $this; }
    public function getAssetHash(): string { return $this->assetHash; }
    public function setAssetHash(string $v): self { $this->assetHash = $v; return $this; }
    public function getOriginalHash(): ?string { return $this->originalHash; }
    public function setOriginalHash(?string $v): self { $this->originalHash = $v; return $this; }
    public function getUrl(): string { return $this->url; }
    public function setUrl(string $v): self { $this->url = $v; return $this; }
    public function getMime(): ?string { return $this->mime; }
    public function setMime(?string $v): self { $this->mime = $v; return $this; }
    public function getSize(): ?int { return $this->size; }
    public function setSize(?int $v): self { $this->size = $v; return $this; }
    public function getDim(): ?string { return $this->dim; }
    public function setDim(?string $v): self { $this->dim = $v; return $this; }
    public function getAlt(): ?string { return $this->alt; }
    public function setAlt(?string $v): self { $this->alt = $v; return $this; }
    public function getMetadataJson(): ?array { return $this->metadataJson; }
    public function setMetadataJson(?array $v): self { $this->metadataJson = $v; return $this; }
    public function getUploadedAt(): ?int { return $this->uploadedAt; }
    public function setUploadedAt(?int $v): self { $this->uploadedAt = $v; return $this; }
    public function getLastSeenAt(): ?int { return $this->lastSeenAt; }
    public function setLastSeenAt(?int $v): self { $this->lastSeenAt = $v; return $this; }
}

