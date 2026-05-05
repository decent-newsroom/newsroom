<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MonitoredRelayRepository;
use App\Util\RelayUrlNormalizer;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Kind 30166 — Relay Monitoring / Discovery event.
 *
 * One row per (monitor_pubkey, relay_url) pair: stores the latest RTT
 * measurements, accepted kinds, supported NIPs, and requirements that the
 * monitoring bot published. The composite primary key matches the NIP-66
 * "one 30166 per monitor per relay" model.
 *
 * @see https://github.com/nostr-protocol/nips/blob/master/66.md
 */
#[ORM\Entity(repositoryClass: MonitoredRelayRepository::class)]
#[ORM\Table(name: 'monitored_relay')]
#[ORM\UniqueConstraint(name: 'uniq_monitored_relay', columns: ['monitor_pubkey', 'relay_url'])]
#[ORM\Index(name: 'idx_monitored_relay_url', columns: ['relay_url'])]
#[ORM\Index(name: 'idx_monitored_relay_observed_at', columns: ['observed_at'])]
class MonitoredRelay
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'monitor_pubkey', length: 64)]
    private string $monitorPubkey;

    #[ORM\Column(name: 'relay_url', length: 255)]
    private string $relayUrl;

    #[ORM\Column(name: 'rtt_open_ms', type: Types::INTEGER, nullable: true)]
    private ?int $rttOpenMs = null;

    #[ORM\Column(name: 'rtt_read_ms', type: Types::INTEGER, nullable: true)]
    private ?int $rttReadMs = null;

    #[ORM\Column(name: 'rtt_write_ms', type: Types::INTEGER, nullable: true)]
    private ?int $rttWriteMs = null;

    /** @var int[] Accepted event kinds (`k` tags) */
    #[ORM\Column(name: 'accepted_kinds', type: Types::JSON, options: ['default' => '[]'])]
    private array $acceptedKinds = [];

    /** @var int[] Supported NIPs (`N` tags) */
    #[ORM\Column(name: 'supported_nips', type: Types::JSON, options: ['default' => '[]'])]
    private array $supportedNips = [];

    /** @var string[] Requirements (`R` tags: `auth`, `payment`, `!auth`, …) */
    #[ORM\Column(name: 'requirements', type: Types::JSON, options: ['default' => '[]'])]
    private array $requirements = [];

    /** @var string[]|null Topics (`T` tags) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $topics = null;

    /** @var string[]|null Geo tags (`g` tags) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $geo = null;

    #[ORM\Column(name: 'event_id', length: 64)]
    private string $eventId;

    #[ORM\Column(name: 'event_created_at', type: Types::BIGINT)]
    private int $eventCreatedAt;

    #[ORM\Column(name: 'observed_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $observedAt;

    public function __construct(string $monitorPubkey, string $relayUrl, string $eventId, int $eventCreatedAt)
    {
        $this->monitorPubkey = $monitorPubkey;
        $this->relayUrl = RelayUrlNormalizer::normalize($relayUrl);
        $this->eventId = $eventId;
        $this->eventCreatedAt = $eventCreatedAt;
        $this->observedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getMonitorPubkey(): string { return $this->monitorPubkey; }
    public function getRelayUrl(): string { return $this->relayUrl; }

    public function getRttOpenMs(): ?int { return $this->rttOpenMs; }
    public function setRttOpenMs(?int $v): self { $this->rttOpenMs = $v; return $this; }

    public function getRttReadMs(): ?int { return $this->rttReadMs; }
    public function setRttReadMs(?int $v): self { $this->rttReadMs = $v; return $this; }

    public function getRttWriteMs(): ?int { return $this->rttWriteMs; }
    public function setRttWriteMs(?int $v): self { $this->rttWriteMs = $v; return $this; }

    /** @return int[] */
    public function getAcceptedKinds(): array { return $this->acceptedKinds; }
    /** @param int[] $v */
    public function setAcceptedKinds(array $v): self { $this->acceptedKinds = $v; return $this; }

    /** @return int[] */
    public function getSupportedNips(): array { return $this->supportedNips; }
    /** @param int[] $v */
    public function setSupportedNips(array $v): self { $this->supportedNips = $v; return $this; }

    /** @return string[] */
    public function getRequirements(): array { return $this->requirements; }
    /** @param string[] $v */
    public function setRequirements(array $v): self { $this->requirements = $v; return $this; }

    /** @return string[]|null */
    public function getTopics(): ?array { return $this->topics; }
    /** @param string[]|null $v */
    public function setTopics(?array $v): self { $this->topics = $v; return $this; }

    /** @return string[]|null */
    public function getGeo(): ?array { return $this->geo; }
    /** @param string[]|null $v */
    public function setGeo(?array $v): self { $this->geo = $v; return $this; }

    public function getEventId(): string { return $this->eventId; }
    public function getEventCreatedAt(): int { return $this->eventCreatedAt; }
    public function getObservedAt(): \DateTimeImmutable { return $this->observedAt; }

    public function requires(string $requirement): bool
    {
        return in_array($requirement, $this->requirements, true);
    }

    public function supportsKind(int $kind): bool
    {
        return in_array($kind, $this->acceptedKinds, true);
    }

    public function updateFrom(string $eventId, int $eventCreatedAt, array $data): self
    {
        $this->eventId = $eventId;
        $this->eventCreatedAt = $eventCreatedAt;
        $this->observedAt = new \DateTimeImmutable();
        foreach ($data as $key => $value) {
            match ($key) {
                'rtt_open_ms'    => $this->rttOpenMs = $value,
                'rtt_read_ms'    => $this->rttReadMs = $value,
                'rtt_write_ms'   => $this->rttWriteMs = $value,
                'accepted_kinds' => $this->acceptedKinds = $value,
                'supported_nips' => $this->supportedNips = $value,
                'requirements'   => $this->requirements = $value,
                'topics'         => $this->topics = $value,
                'geo'            => $this->geo = $value,
                default          => null,
            };
        }
        return $this;
    }
}

