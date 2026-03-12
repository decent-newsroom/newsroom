<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserUploadRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tracks files uploaded by a user to external media providers.
 *
 * Every successful upload through the proxy records the returned URL so it
 * can be surfaced later in the Media Manager, article editor, etc.
 */
#[ORM\Entity(repositoryClass: UserUploadRepository::class)]
#[ORM\Table(name: 'user_upload')]
#[ORM\Index(name: 'idx_user_upload_npub', columns: ['npub'])]
#[ORM\Index(name: 'idx_user_upload_created', columns: ['npub', 'created_at'])]
class UserUpload
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /** The user's npub who uploaded the file. */
    #[ORM\Column(length: 255)]
    private string $npub = '';

    /** The public URL returned by the provider after a successful upload. */
    #[ORM\Column(length: 1024)]
    private string $url = '';

    /** Provider identifier (e.g. "nostrbuild", "blossomband"). */
    #[ORM\Column(length: 64)]
    private string $provider = '';

    /** MIME type of the uploaded file (e.g. "image/jpeg"). */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $mimeType = null;

    /** Original filename the user selected. */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $originalFilename = null;

    /** File size in bytes (if known). */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $fileSize = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // -- Getters / setters ---------------------------------------------------

    public function getId(): ?int { return $this->id; }

    public function getNpub(): string { return $this->npub; }
    public function setNpub(string $npub): self { $this->npub = $npub; return $this; }

    public function getUrl(): string { return $this->url; }
    public function setUrl(string $url): self { $this->url = $url; return $this; }

    public function getProvider(): string { return $this->provider; }
    public function setProvider(string $provider): self { $this->provider = $provider; return $this; }

    public function getMimeType(): ?string { return $this->mimeType; }
    public function setMimeType(?string $mimeType): self { $this->mimeType = $mimeType; return $this; }

    public function getOriginalFilename(): ?string { return $this->originalFilename; }
    public function setOriginalFilename(?string $originalFilename): self { $this->originalFilename = $originalFilename; return $this; }

    public function getFileSize(): ?int { return $this->fileSize; }
    public function setFileSize(?int $fileSize): self { $this->fileSize = $fileSize; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function setCreatedAt(\DateTimeImmutable $createdAt): self { $this->createdAt = $createdAt; return $this; }

    /**
     * Serialise for JSON API responses.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'provider' => $this->provider,
            'mime_type' => $this->mimeType,
            'original_filename' => $this->originalFilename,
            'file_size' => $this->fileSize,
            'created_at' => $this->createdAt->format('c'),
        ];
    }
}

