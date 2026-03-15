<?php

declare(strict_types=1);

namespace App\Service\Graph;

use App\Entity\Event;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;

/**
 * Keeps parsed_reference and current_record in sync when events are ingested.
 *
 * Call processEvent() after persisting any Event entity to the database.
 * This replaces the need for periodic full-table backfills.
 */
class EventIngestionListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ReferenceParserService $referenceParser,
        private readonly CurrentVersionResolver $currentVersionResolver,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Process a newly ingested event: update parsed_reference and current_record.
     *
     * Safe to call multiple times for the same event (idempotent).
     */
    public function processEvent(Event $event): void
    {
        $this->updateReferences($event);
        $this->updateCurrentRecord($event);
    }

    /**
     * Process a raw event (stdClass from relay) without requiring an Event entity.
     */
    public function processRawEvent(object $raw): void
    {
        $tags = is_array($raw->tags ?? null) ? $raw->tags : [];
        $kind = (int) ($raw->kind ?? 0);
        $eventId = $raw->id ?? '';
        $pubkey = $raw->pubkey ?? '';
        $createdAt = (int) ($raw->created_at ?? 0);

        if (empty($eventId)) {
            return;
        }

        // Update references
        $refs = $this->referenceParser->parseFromTagsArray($eventId, $kind, $tags);
        if (!empty($refs)) {
            $this->insertReferences($refs);
        }

        // Update current record
        $dTag = null;
        if ($kind >= 30000 && $kind <= 39999) {
            $dTag = '';
            foreach ($tags as $tag) {
                if (is_array($tag) && ($tag[0] ?? '') === 'd' && array_key_exists(1, $tag)) {
                    $dTag = (string) $tag[1];
                    break;
                }
            }
        }

        $this->currentVersionResolver->updateIfCurrent($eventId, $kind, $pubkey, $dTag, $createdAt);
    }

    /**
     * Parse and store references for an event.
     */
    private function updateReferences(Event $event): void
    {
        $refs = $this->referenceParser->parseReferences($event);

        if (empty($refs)) {
            return;
        }

        // Delete existing references for this event (idempotent)
        $this->connection->executeStatement(
            'DELETE FROM parsed_reference WHERE source_event_id = ?',
            [$event->getId()]
        );

        $this->insertReferences($refs);
    }

    /**
     * Update current_record if this event is a replaceable type.
     */
    private function updateCurrentRecord(Event $event): void
    {
        $this->currentVersionResolver->updateIfCurrent(
            eventId: $event->getId(),
            kind: $event->getKind(),
            pubkey: $event->getPubkey(),
            dTag: $event->getDTag(),
            createdAt: $event->getCreatedAt(),
        );
    }

    /**
     * @param ParsedReferenceDto[] $refs
     */
    private function insertReferences(array $refs): void
    {
        foreach ($refs as $ref) {
            try {
                $this->connection->executeStatement(
                    'INSERT INTO parsed_reference (source_event_id, tag_name, target_ref_type, target_kind, target_pubkey, target_d_tag, target_coord, relation, marker, position, is_structural, is_resolvable) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $ref->sourceEventId, $ref->tagName, $ref->targetRefType,
                        $ref->targetKind, $ref->targetPubkey, $ref->targetDTag,
                        $ref->targetCoord, $ref->relation, $ref->marker,
                        $ref->position, $ref->isStructural, $ref->isResolvable,
                    ],
                    [
                        ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                        ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING,
                        ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                        ParameterType::INTEGER, ParameterType::BOOLEAN, ParameterType::BOOLEAN,
                    ]
                );
            } catch (\Throwable $e) {
                $this->logger->warning('EventIngestionListener: failed to insert reference', [
                    'source' => $ref->sourceEventId,
                    'target' => $ref->targetCoord,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}

