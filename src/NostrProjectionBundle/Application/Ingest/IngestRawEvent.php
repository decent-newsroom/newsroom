<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrProjectionBundle\Application\Ingest;

use DecentNewsroom\NostrKernelBundle\Contract\Event\EventValidatorInterface;
use DecentNewsroom\NostrProjectionBundle\Domain\Event\EventIngestionResult;

final readonly class IngestRawEvent
{
    public function __construct(
        private EventValidatorInterface $rawEventVerifier,
        private IngestVerifiedEvent $ingestVerifiedEvent,
    ) {
    }

    /**
     * @param array<string, mixed> $rawEvent
     */
    public function __invoke(array $rawEvent, ?string $sourceRelay = null): EventIngestionResult
    {
        $verified = $this->rawEventVerifier->validate($rawEvent);

        return ($this->ingestVerifiedEvent)($verified, $sourceRelay);
    }
}
