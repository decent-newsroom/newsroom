<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\NotificationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A delivered notification item for a specific user. Created by the fan-out
 * handler when an ingested Nostr event matches at least one of the user's
 * active {@see NotificationSubscription} rows.
 *
 * v1 delivers kinds 30023 and 30040 only.
 */
#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification')]
#[ORM\UniqueConstraint(name: 'uniq_notification_user_event', columns: ['user_id', 'event_id'])]
#[ORM\Index(name: 'idx_notification_user_unread', columns: ['user_id', 'read_at'])]
#[ORM\Index(name: 'idx_notification_user_created', columns: ['user_id', 'created_at'])]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: NotificationSubscription::class)]
    #[ORM\JoinColumn(name: 'subscription_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?NotificationSubscription $subscription = null;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $eventId;

    #[ORM\Column(type: Types::INTEGER)]
    private int $eventKind;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $eventPubkey;

    #[ORM\Column(type: Types::STRING, length: 512, nullable: true)]
    private ?string $eventCoordinate = null;

    #[ORM\Column(type: Types::STRING, length: 512, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::STRING, length: 1024, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: Types::STRING, length: 1024)]
    private string $url;

    /** Nostr `created_at` of the event (not ingestion time). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** Set when the user opens the notifications page and the bell badge clears. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $seenAt = null;

    /** Set when the user explicitly marks this notification as read. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(
        User $user,
        string $eventId,
        int $eventKind,
        string $eventPubkey,
        string $url,
        \DateTimeImmutable $createdAt,
        ?NotificationSubscription $subscription = null,
        ?string $eventCoordinate = null,
        ?string $title = null,
        ?string $summary = null,
    ) {
        $this->user = $user;
        $this->eventId = $eventId;
        $this->eventKind = $eventKind;
        $this->eventPubkey = $eventPubkey;
        $this->url = $url;
        $this->createdAt = $createdAt;
        $this->subscription = $subscription;
        $this->eventCoordinate = $eventCoordinate;
        $this->title = $title;
        $this->summary = $summary;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSubscription(): ?NotificationSubscription
    {
        return $this->subscription;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getEventKind(): int
    {
        return $this->eventKind;
    }

    public function getEventPubkey(): string
    {
        return $this->eventPubkey;
    }

    public function getEventCoordinate(): ?string
    {
        return $this->eventCoordinate;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSeenAt(): ?\DateTimeImmutable
    {
        return $this->seenAt;
    }

    public function markSeen(?\DateTimeImmutable $at = null): self
    {
        $this->seenAt = $at ?? new \DateTimeImmutable();
        return $this;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }

    public function markRead(?\DateTimeImmutable $at = null): self
    {
        $this->readAt = $at ?? new \DateTimeImmutable();
        if ($this->seenAt === null) {
            $this->seenAt = $this->readAt;
        }
        return $this;
    }
}

