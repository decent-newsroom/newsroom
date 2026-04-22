<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DeletedEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tombstone record for a NIP-09 (kind 5) deletion request that has been honored.
 *
 * One row is stored per referenced event/coordinate (from the `e` / `a` tags of
 * the deletion request), provided the pubkey of the target matches the pubkey
 * of the deletion request author.
 *
 * Tombstones persist indefinitely so that late-arriving re-publishes of the
 * same event (or older versions of a replaceable coordinate) are suppressed at
 * ingestion time.
 */
#[ORM\Entity(repositoryClass: DeletedEventRepository::class)]
#[ORM\Table(name: 'deleted_event')]
#[ORM\Index(columns: ['target_ref'], name: 'idx_deleted_event_target_ref')]
#[ORM\Index(columns: ['pubkey'], name: 'idx_deleted_event_pubkey')]
#[ORM\UniqueConstraint(name: 'uniq_deleted_event_target_ref', columns: ['target_ref'])]
class DeletedEvent
{
    public const REF_EVENT_ID = 'e';
    public const REF_COORDINATE = 'a';

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Either a 64-char hex event id (when refType = 'e') or a
     * `kind:pubkey:d` coordinate (when refType = 'a').
     */
    #[ORM\Column(type: Types::STRING, length: 512)]
    private string $targetRef;

    #[ORM\Column(type: Types::STRING, length: 1)]
    private string $refType;

    /** Hex pubkey of the deletion request author (== target author). */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $pubkey;

    /** Kind of the referenced event, when known (from `k` tag or resolution). */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $kind = null;

    /** ID of the kind:5 deletion request event itself. */
    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $deletionEventId;

    /** created_at of the deletion request — suppression window upper bound for `a` refs. */
    #[ORM\Column(type: Types::BIGINT)]
    private int $deletionCreatedAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $recordedAt;

    public function __construct()
    {
        $this->recordedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getTargetRef(): string { return $this->targetRef; }
    public function setTargetRef(string $targetRef): self { $this->targetRef = $targetRef; return $this; }

    public function getRefType(): string { return $this->refType; }
    public function setRefType(string $refType): self { $this->refType = $refType; return $this; }

    public function getPubkey(): string { return $this->pubkey; }
    public function setPubkey(string $pubkey): self { $this->pubkey = $pubkey; return $this; }

    public function getKind(): ?int { return $this->kind; }
    public function setKind(?int $kind): self { $this->kind = $kind; return $this; }

    public function getDeletionEventId(): string { return $this->deletionEventId; }
    public function setDeletionEventId(string $id): self { $this->deletionEventId = $id; return $this; }

    public function getDeletionCreatedAt(): int { return $this->deletionCreatedAt; }
    public function setDeletionCreatedAt(int $t): self { $this->deletionCreatedAt = $t; return $this; }

    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $reason): self { $this->reason = $reason; return $this; }

    public function getRecordedAt(): \DateTimeImmutable { return $this->recordedAt; }
}

