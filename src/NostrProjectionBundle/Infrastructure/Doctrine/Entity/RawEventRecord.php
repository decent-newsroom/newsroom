<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Infrastructure\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'nostr_raw_event')]
#[ORM\Index(name: 'idx_nostr_raw_event_pubkey', columns: ['pubkey'])]
#[ORM\Index(name: 'idx_nostr_raw_event_kind', columns: ['kind'])]
#[ORM\Index(name: 'idx_nostr_raw_event_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_nostr_raw_event_pubkey_kind', columns: ['pubkey', 'kind'])]
class RawEventRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 64)]
    public string $id;

    #[ORM\Column(type: 'string', length: 64)]
    public string $pubkey;

    #[ORM\Column(type: 'integer')]
    public int $kind;

    #[ORM\Column(name: 'created_at', type: 'integer')]
    public int $createdAt;

    #[ORM\Column(type: 'text')]
    public string $content;

    /** @var array<int, array<int, string>> */
    #[ORM\Column(type: 'json')]
    public array $tags = [];

    #[ORM\Column(type: 'string', length: 128)]
    public string $sig;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    public array $raw = [];

    /** @var list<string> */
    #[ORM\Column(name: 'source_relays', type: 'json')]
    public array $sourceRelays = [];

    #[ORM\Column(name: 'first_seen_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(name: 'last_seen_at', type: 'datetime_immutable')]
    public \DateTimeImmutable $lastSeenAt;
}
