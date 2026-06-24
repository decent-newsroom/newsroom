<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Infrastructure\Doctrine\Repository;

use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;
use DecentNewsroom\NostrProjectionBundle\Contract\Store\CurrentRecordStoreInterface;
use DecentNewsroom\NostrProjectionBundle\Domain\Event\CurrentRecord;
use Doctrine\DBAL\Connection;

final readonly class DoctrineCurrentRecordStore implements CurrentRecordStoreInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function upsertIfCurrent(NostrEvent $event): ?CurrentRecord
    {
        $coordinate = $this->coordinateFor($event);

        if ($coordinate === null) {
            return null;
        }

        $existing = $this->connection->fetchAssociative(
            'SELECT event_id, created_at FROM nostr_current_record WHERE coordinate = :coordinate',
            ['coordinate' => $coordinate],
        );

        $shouldReplace = $existing === false
            || (int) $existing['created_at'] < $event->createdAt
            || ((int) $existing['created_at'] === $event->createdAt && strcmp($event->id->value, (string) $existing['event_id']) > 0);

        if (!$shouldReplace) {
            return new CurrentRecord(
                coordinate: $coordinate,
                eventId: $event->id,
                pubkey: $event->pubkey->value,
                kind: $event->kind->value,
                dTag: $this->dTag($event),
                createdAt: $event->createdAt,
                changed: false,
            );
        }

        $this->connection->executeStatement(
            <<<'SQL'
INSERT INTO nostr_current_record (coordinate, event_id, pubkey, kind, d_tag, created_at, updated_at)
VALUES (:coordinate, :event_id, :pubkey, :kind, :d_tag, :created_at, :updated_at)
ON CONFLICT (coordinate) DO UPDATE SET
    event_id = EXCLUDED.event_id,
    pubkey = EXCLUDED.pubkey,
    kind = EXCLUDED.kind,
    d_tag = EXCLUDED.d_tag,
    created_at = EXCLUDED.created_at,
    updated_at = EXCLUDED.updated_at
SQL,
            [
                'coordinate' => $coordinate,
                'event_id' => $event->id->value,
                'pubkey' => $event->pubkey->value,
                'kind' => $event->kind->value,
                'd_tag' => $this->dTag($event),
                'created_at' => $event->createdAt,
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );

        return new CurrentRecord(
            coordinate: $coordinate,
            eventId: $event->id,
            pubkey: $event->pubkey->value,
            kind: $event->kind->value,
            dTag: $this->dTag($event),
            createdAt: $event->createdAt,
            changed: true,
        );
    }

    private function coordinateFor(NostrEvent $event): ?string
    {
        if ($event->kind->isAddressable()) {
            $dTag = $this->dTag($event);

            if ($dTag === null || $dTag === '') {
                return null;
            }

            return sprintf('%d:%s:%s', $event->kind->value, $event->pubkey->value, $dTag);
        }

        if ($event->kind->isReplaceable()) {
            return sprintf('%d:%s', $event->kind->value, $event->pubkey->value);
        }

        return null;
    }

    private function dTag(NostrEvent $event): ?string
    {
        if (method_exists($event->tags, 'dTag')) {
            return $event->tags->dTag();
        }

        if (method_exists($event->tags, 'firstValue')) {
            return $event->tags->firstValue('d');
        }

        foreach ($event->tags->toArray() as $tag) {
            if (($tag[0] ?? null) === 'd') {
                return $tag[1] ?? null;
            }
        }

        return null;
    }
}
