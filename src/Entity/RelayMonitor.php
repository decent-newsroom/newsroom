<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RelayMonitorRepository;
use App\Util\RelayUrlNormalizer;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Kind 10166 — Relay Monitor Announcement (replaceable, one per monitor pubkey).
 *
 * Published by monitoring bots to advertise that they are actively checking
 * relay health and publishing kind 30166 events. Stored here so the admin
 * dashboard can show which monitors are known and which are trusted.
 *
 * @see https://github.com/nostr-protocol/nips/blob/master/66.md
 */
#[ORM\Entity(repositoryClass: RelayMonitorRepository::class)]
#[ORM\Table(name: 'relay_monitor')]
class RelayMonitor
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $pubkey;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    /** Monitoring interval in seconds, from the `frequency` tag */
    #[ORM\Column(name: 'frequency_seconds', type: Types::INTEGER, nullable: true)]
    private ?int $frequencySeconds = null;

    /** @var string[] List of relay URLs this monitor claims to watch */
    #[ORM\Column(name: 'monitored_relays', type: Types::JSON, options: ['default' => '[]'])]
    private array $monitoredRelays = [];

    /** @var string[] Kinds/NIPs the monitor checks */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $checks = null;

    #[ORM\Column(name: 'event_id', length: 64)]
    private string $eventId;

    #[ORM\Column(name: 'event_created_at', type: Types::BIGINT)]
    private int $eventCreatedAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $pubkey, string $eventId, int $eventCreatedAt)
    {
        $this->pubkey = $pubkey;
        $this->eventId = $eventId;
        $this->eventCreatedAt = $eventCreatedAt;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getPubkey(): string { return $this->pubkey; }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $v): self { $this->name = $v; return $this; }

    public function getFrequencySeconds(): ?int { return $this->frequencySeconds; }
    public function setFrequencySeconds(?int $v): self { $this->frequencySeconds = $v; return $this; }

    /** @return string[] */
    public function getMonitoredRelays(): array { return $this->monitoredRelays; }
    /** @param string[] $v */
    public function setMonitoredRelays(array $v): self { $this->monitoredRelays = $v; return $this; }

    /** @return string[]|null */
    public function getChecks(): ?array { return $this->checks; }
    /** @param string[]|null $v */
    public function setChecks(?array $v): self { $this->checks = $v; return $this; }

    public function getEventId(): string { return $this->eventId; }
    public function getEventCreatedAt(): int { return $this->eventCreatedAt; }

    public function touch(string $eventId, int $eventCreatedAt): self
    {
        $this->eventId = $eventId;
        $this->eventCreatedAt = $eventCreatedAt;
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}

