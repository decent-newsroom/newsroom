<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Application\Ingest;

use DecentNewsroom\NostrKernelBundle\Contract\Event\EventReferenceParserInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Event\VerifiedNostrEvent;
use DecentNewsroom\NostrProjectionBundle\Contract\Projection\ProjectionDispatcherInterface;
use DecentNewsroom\NostrProjectionBundle\Contract\Store\CurrentRecordStoreInterface;
use DecentNewsroom\NostrProjectionBundle\Contract\Store\EventReferenceStoreInterface;
use DecentNewsroom\NostrProjectionBundle\Contract\Store\RawEventStoreInterface;
use DecentNewsroom\NostrProjectionBundle\Domain\Event\EventIngestionResult;

final readonly class IngestVerifiedEvent
{
    public function __construct(
        private RawEventStoreInterface $rawEventStore,
        private CurrentRecordStoreInterface $currentRecordStore,
        private EventReferenceParserInterface $referenceParser,
        private EventReferenceStoreInterface $referenceStore,
        private ProjectionDispatcherInterface $projectionDispatcher,
    ) {
    }

    public function __invoke(VerifiedNostrEvent $verified, ?string $sourceRelay = null): EventIngestionResult
    {
        $event = $verified->event;

        $stored = $this->rawEventStore->upsert($event, $sourceRelay);
        $current = $this->currentRecordStore->upsertIfCurrent($event);

        $this->referenceStore->replaceForEvent(
            eventId: $event->id,
            references: $this->referenceParser->parseReferences($event),
        );

        $this->projectionDispatcher->dispatch($verified);

        return new EventIngestionResult(
            eventId: $event->id,
            inserted: $stored->inserted,
            currentRecordChanged: $current?->changed ?? false,
        );
    }
}
