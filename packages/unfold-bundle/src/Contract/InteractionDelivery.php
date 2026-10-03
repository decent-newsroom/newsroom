<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

final readonly class InteractionDelivery
{
    /** @param array<string, mixed> $relayResults */
    public function __construct(
        public string $eventId,
        public string $status,
        public bool $localCommit,
        public array $relayResults = [],
        public ?string $error = null,
        public ?bool $retryable = null,
    ) {
        if (!in_array($status, ['queued', 'published', 'partial', 'failed'], true)) {
            throw new \InvalidArgumentException('Invalid interaction delivery status.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'status' => $this->status,
            'local_commit' => $this->localCommit,
            'published' => in_array($this->status, ['published', 'partial'], true),
            'relay_complete' => $this->status === 'published',
            'retryable' => $this->retryable ?? in_array($this->status, ['partial', 'failed'], true),
            'relay_results' => $this->relayResults,
            'error' => $this->error,
        ];
    }
}
