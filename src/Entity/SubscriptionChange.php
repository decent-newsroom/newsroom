<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SubscriptionChangeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A local tag snapshot and semantic delta, not a Nostr event or a rendered body.
 * @phpstan-import-type Delta from \App\Service\Update\SubscriptionDiffer
 */
#[ORM\Entity(repositoryClass: SubscriptionChangeRepository::class)]
#[ORM\Table(name: 'subscription_change')]
#[ORM\Index(name: 'idx_subscription_change_source', columns: ['coordinate', 'created_at'])]
class SubscriptionChange
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $eventId;

    #[ORM\Column(length: 512)]
    private string $coordinate;

    #[ORM\Column]
    private int $kind;

    #[ORM\Column(length: 64)]
    private string $pubkey;

    #[ORM\Column]
    private int $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $recordedAt;

    /** @var array<array-key, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $tags;

    #[ORM\Column(length: 64)]
    private string $contentHash;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $previousEventId;

    /** @var Delta|array{} */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $changes;

    /** @param Delta|array{} $changes */
    public function __construct(Event $event, string $coordinate, ?self $previous, array $changes)
    {
        $this->eventId = $event->getId();
        $this->coordinate = $coordinate;
        $this->kind = $event->getKind();
        $this->pubkey = $event->getPubkey();
        $this->createdAt = $event->getCreatedAt();
        $this->recordedAt = new \DateTimeImmutable();
        $this->tags = $event->getTags();
        $this->contentHash = hash('sha256', $event->getContent());
        $this->previousEventId = $previous?->getEventId();
        $this->changes = $changes;
    }

    public function getEventId(): string { return $this->eventId; }
    public function getCoordinate(): string { return $this->coordinate; }
    public function getKind(): int { return $this->kind; }
    public function getPubkey(): string { return $this->pubkey; }
    public function getCreatedAt(): int { return $this->createdAt; }
    public function getRecordedAt(): \DateTimeImmutable { return $this->recordedAt; }
    /** @return array<array-key, mixed> */
    public function getTags(): array { return $this->tags; }
    public function getContentHash(): string { return $this->contentHash; }
    public function getPreviousEventId(): ?string { return $this->previousEventId; }
    /** @return Delta|array{} */
    public function getChanges(): array { return $this->changes; }

    public function hasChanges(): bool
    {
        return ($this->changes['added'] ?? []) !== [] || ($this->changes['removed'] ?? []) !== []
            || ($this->changes['metadata'] ?? []) !== [] || ($this->changes['content'] ?? false)
            || ($this->changes['reordered'] ?? false);
    }

    /** Metadata-only Event shape for existing identity/tag policy; never render it as a signed body. */
    public function metadataEvent(): Event
    {
        $event = new Event();
        $event->setId($this->eventId);
        $event->setKind($this->kind);
        $event->setPubkey($this->pubkey);
        $event->setCreatedAt($this->createdAt);
        $event->setTags($this->tags);
        $event->setContent('');
        $event->extractAndSetDTag();
        return $event;
    }
}
