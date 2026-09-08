<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use Innis\Nostr\Core\Domain\Entity\Event;

/**
 * Converts signed event payloads into core events and verifies their signatures.
 */
final readonly class NostrEventVerifier
{
    public function __construct(
        private NostrSigner $signer,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function fromArray(array $payload): Event
    {
        return Event::fromArray($payload);
    }

    public function verify(Event $event): bool
    {
        return $this->signer->verify($event);
    }
}
