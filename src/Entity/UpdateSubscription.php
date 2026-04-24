<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UpdateSourceTypeEnum;
use App\Repository\UpdateSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user's active subscription to an update source (npub, publication
 * coordinate, or NIP-51 set coordinate). See {@see UpdateSourceTypeEnum}.
 */
#[ORM\Entity(repositoryClass: UpdateSubscriptionRepository::class)]
#[ORM\Table(name: 'notification_subscription')]
#[ORM\UniqueConstraint(
    name: 'uniq_notification_subscription',
    columns: ['user_id', 'source_type', 'source_value']
)]
#[ORM\Index(name: 'idx_notification_subscription_active_type', columns: ['active', 'source_type'])]
#[ORM\Index(name: 'idx_notification_subscription_user', columns: ['user_id'])]
class UpdateSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: UpdateSourceTypeEnum::class)]
    private UpdateSourceTypeEnum $sourceType;

    #[ORM\Column(type: Types::STRING, length: 512)]
    private string $sourceValue;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        User $user,
        UpdateSourceTypeEnum $sourceType,
        string $sourceValue,
        ?string $label = null,
    ) {
        $this->user = $user;
        $this->sourceType = $sourceType;
        $this->sourceValue = $sourceValue;
        $this->label = $label;
        $this->active = true;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSourceType(): UpdateSourceTypeEnum
    {
        return $this->sourceType;
    }

    public function getSourceValue(): string
    {
        return $this->sourceValue;
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

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

