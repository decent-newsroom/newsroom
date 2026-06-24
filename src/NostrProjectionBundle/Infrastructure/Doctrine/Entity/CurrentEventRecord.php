<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Infrastructure\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'nostr_current_record')]
#[ORM\Index(name: 'idx_nostr_current_record_event_id', columns: ['event_id'])]
#[ORM\Index(name: 'idx_nostr_current_record_pubkey_kind', columns: ['pubkey', 'kind'])]
class CurrentEventRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 512)]
    public string $coordinate;

    #[ORM\Column(name: 'event_id', type: 'string', length: 64)]
    public string $eventId;

    #[ORM\Column(type: 'string', length: 64)]
    public string $pubkey;

    #[ORM\Column(type: 'integer')]
    public int $kind;

    #[ORM\Column(name: 'd_tag', type: 'text', nullable: true)]
    public ?string $dTag = null;

    #[ORM\Column(name: 'created_at', type: 'integer')]
    public int $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $updatedAt;
}
