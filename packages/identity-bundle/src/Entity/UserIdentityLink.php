<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Entity;

use DecentNewsroom\IdentityBundle\Repository\UserIdentityLinkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Links one external identity (provider + externalId) to a host application
 * user (identified by the opaque `ownerId` string — see
 * {@see \DecentNewsroom\IdentityBundle\Contract\IdentityOwnerInterface}).
 *
 * A single `ownerId` can have many `UserIdentityLink` rows (one per linked
 * auth method), which is how one local user ends up able to log in via
 * Nostr, email OTP, a passkey, and/or OAuth interchangeably.
 */
#[ORM\Entity(repositoryClass: UserIdentityLinkRepository::class)]
#[ORM\Table(name: 'identity_user_link')]
#[ORM\UniqueConstraint(name: 'uniq_provider_external_id', columns: ['provider', 'external_id'])]
#[ORM\Index(columns: ['owner_id'], name: 'idx_identity_link_owner_id')]
class UserIdentityLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Opaque foreign "key" into the host application's own user table.
     * Deliberately not a Doctrine relation — see class docblock.
     */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $ownerId;

    /**
     * Machine name of the identity provider, e.g. "nostr", "email_otp",
     * "passkey", "oauth_google".
     */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $provider;

    /**
     * Provider-specific unique identifier: hex pubkey for Nostr, normalized
     * email address for email OTP, WebAuthn credential id for passkeys,
     * "{provider}:{sub}" for OAuth.
     */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $externalId;

    /**
     * Optional human-readable label shown in the "linked identities" UI
     * (e.g. a passkey's device name, or the OAuth account's display name).
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $label = null;

    /**
     * Provider-specific auxiliary data (e.g. WebAuthn credential public key
     * and sign counter). Never used to store raw secrets in plaintext.
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(string $ownerId, string $provider, string $externalId)
    {
        $this->ownerId = $ownerId;
        $this->provider = $provider;
        $this->externalId = $externalId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwnerId(): string
    {
        return $this->ownerId;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function markVerified(\DateTimeImmutable $at = new \DateTimeImmutable()): self
    {
        $this->verifiedAt = $at;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function touchLastUsed(\DateTimeImmutable $at = new \DateTimeImmutable()): self
    {
        $this->lastUsedAt = $at;

        return $this;
    }
}
