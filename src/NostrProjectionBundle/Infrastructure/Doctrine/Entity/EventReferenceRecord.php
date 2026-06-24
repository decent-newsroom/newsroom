<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Infrastructure\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'nostr_event_reference')]
#[ORM\Index(name: 'idx_nostr_event_reference_source', columns: ['source_event_id'])]
#[ORM\Index(name: 'idx_nostr_event_reference_type_value', columns: ['type', 'value'])]
class EventReferenceRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    public ?string $id = null;

    #[ORM\Column(name: 'source_event_id', type: 'string', length: 64)]
    public string $sourceEventId;

    #[ORM\Column(type: 'string', length: 32)]
    public string $type;

    #[ORM\Column(type: 'text')]
    public string $value;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $marker = null;

    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $relay = null;

    /** @var array<int, string> */
    #[ORM\Column(name: 'raw_tag', type: 'json')]
    public array $rawTag = [];
}
