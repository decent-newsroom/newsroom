<?php

declare(strict_types=1);

namespace App\Service\Nostr;

/**
 * Typed request contract shared by direct AMPHP queries and gateway routing.
 *
 * @param array<int, array<string, mixed>> $filters
 */
final class RelayQueryRequest
{
    private int $timeout = 3;
    private int $gatewayTimeout = 3;
    private ?string $stopOnEventId = null;
    private ?string $requestedBy = null;
    private readonly string $subscriptionId;

    public function __construct(
        private readonly RelaySet $relaySet,
        private readonly array $filters,
    ) {
        $this->subscriptionId = 'app-'.bin2hex(random_bytes(8));
    }

    public function getRelaySet(): RelaySet
    {
        return $this->relaySet;
    }

    /** @return array<int, array<string, mixed>> */
    public function getFilters(): array
    {
        return $this->filters;
    }

    public function getSubscriptionId(): string
    {
        return $this->subscriptionId;
    }

    public function setTimeout(int $timeout): self
    {
        $this->timeout = max(1, $timeout);

        return $this;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function setGatewayTimeout(int $timeout): self
    {
        $this->gatewayTimeout = max(1, $timeout);

        return $this;
    }

    public function getGatewayTimeout(): int
    {
        return $this->gatewayTimeout;
    }

    public function stopOnEventId(?string $eventId): self
    {
        $this->stopOnEventId = $eventId;

        return $this;
    }

    public function getStopOnEventId(): ?string
    {
        return $this->stopOnEventId;
    }

    public function requestedBy(?string $pubkey): self
    {
        $this->requestedBy = $pubkey;

        return $this;
    }

    public function getRequestedBy(): ?string
    {
        return $this->requestedBy;
    }

    public function toPayload(): string
    {
        return json_encode(
            array_merge(['REQ', $this->subscriptionId], $this->filters),
            JSON_THROW_ON_ERROR,
        );
    }
}
