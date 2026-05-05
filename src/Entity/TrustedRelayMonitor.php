<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrustedRelayMonitorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A relay monitor pubkey that the instance operator has explicitly trusted.
 *
 * Only 30166 events from trusted monitor pubkeys are projected into
 * {@see MonitoredRelay}. This prevents spam and untrusted data from
 * influencing relay scoring.
 *
 * Bootstrap values may be seeded from the `relay_discovery.bootstrap_monitor_pubkeys`
 * services.yaml parameter on cold start.
 */
#[ORM\Entity(repositoryClass: TrustedRelayMonitorRepository::class)]
#[ORM\Table(name: 'trusted_relay_monitor')]
class TrustedRelayMonitor
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $pubkey;

    #[ORM\Column(name: 'trusted_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $trustedAt;

    #[ORM\Column(name: 'trusted_by_user_id', type: Types::INTEGER, nullable: true)]
    private ?int $trustedByUserId = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $note = null;

    public function __construct(string $pubkey, ?int $trustedByUserId = null, ?string $note = null)
    {
        $this->pubkey = $pubkey;
        $this->trustedAt = new \DateTimeImmutable();
        $this->trustedByUserId = $trustedByUserId;
        $this->note = $note;
    }

    public function getPubkey(): string { return $this->pubkey; }
    public function getTrustedAt(): \DateTimeImmutable { return $this->trustedAt; }
    public function getTrustedByUserId(): ?int { return $this->trustedByUserId; }
    public function getNote(): ?string { return $this->note; }
    public function setNote(?string $v): self { $this->note = $v; return $this; }
}

