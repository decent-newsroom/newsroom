<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use Innis\Nostr\Core\Domain\Entity\Event;

/**
 * Result of one relay query, including transport-independent health metadata.
 *
 * @param Event[] $events
 */
final readonly class RelayQueryResult
{
    public function __construct(
        public string $relayUrl,
        public array $events = [],
        public bool $eose = false,
        public ?string $error = null,
        public ?int $latencyMs = null,
    ) {
    }

    public function hasEvents(): bool
    {
        return $this->events !== [];
    }
}
