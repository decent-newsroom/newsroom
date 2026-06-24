<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Infrastructure\Doctrine\Repository;

use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;
use DecentNewsroom\NostrProjectionBundle\Contract\Store\RawEventStoreInterface;
use DecentNewsroom\NostrProjectionBundle\Domain\Event\StoredRawEvent;
use Doctrine\DBAL\Connection;

final readonly class DoctrineRawEventStore implements RawEventStoreInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function upsert(NostrEvent $event, ?string $sourceRelay = null): StoredRawEvent
    {
        $eventId = $event->id->value;
        $now = new \DateTimeImmutable();
        $sourceRelays = $sourceRelay === null ? [] : [$sourceRelay];

        $inserted = $this->connection->fetchOne(
            'SELECT 1 FROM nostr_raw_event WHERE id = :id',
            ['id' => $eventId],
        ) === false;

        $this->connection->executeStatement(
            <<<'SQL'
INSERT INTO nostr_raw_event (
    id, pubkey, kind, created_at, content, tags, sig, raw, source_relays, first_seen_at, last_seen_at
) VALUES (
    :id, :pubkey, :kind, :created_at, :content, :tags, :sig, :raw, :source_relays, :first_seen_at, :last_seen_at
)
ON CONFLICT (id) DO UPDATE SET
    last_seen_at = EXCLUDED.last_seen_at,
    source_relays = (
        SELECT jsonb_agg(DISTINCT relay)
        FROM jsonb_array_elements_text(nostr_raw_event.source_relays::jsonb || EXCLUDED.source_relays::jsonb) AS relay
    )
SQL,
            [
                'id' => $eventId,
                'pubkey' => $event->pubkey->value,
                'kind' => $event->kind->value,
                'created_at' => $event->createdAt,
                'content' => $event->content,
                'tags' => json_encode($event->tags->toArray(), JSON_THROW_ON_ERROR),
                'sig' => $event->sig->value,
                'raw' => json_encode($this->raw($event), JSON_THROW_ON_ERROR),
                'source_relays' => json_encode($sourceRelays, JSON_THROW_ON_ERROR),
                'first_seen_at' => $now->format('Y-m-d H:i:s'),
                'last_seen_at' => $now->format('Y-m-d H:i:s'),
            ],
        );

        return new StoredRawEvent(
            eventId: $event->id,
            inserted: $inserted,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function raw(NostrEvent $event): array
    {
        if (method_exists($event, 'raw')) {
            /** @var array<string, mixed> $raw */
            $raw = $event->raw();

            return $raw === [] ? $event->toArray() : $raw;
        }

        return $event->toArray();
    }
}
