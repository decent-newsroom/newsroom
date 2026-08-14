<?php

declare(strict_types=1);

namespace App\RelayGateway;

use App\Service\Nostr\RelayHealthStore;
use DecentNewsroom\RelayGatewayBundle\Contract\GatewayHealthRecorderInterface;

final readonly class GatewayHealthRecorderAdapter implements GatewayHealthRecorderInterface
{
    public function __construct(private RelayHealthStore $healthStore)
    {
    }

    public function recordSuccess(string $relayUrl, ?int $latencyMs = null): void
    {
        $this->healthStore->recordSuccess($relayUrl, $latencyMs);
    }

    public function recordFailure(string $relayUrl): void
    {
        $this->healthStore->recordFailure($relayUrl);
    }

    public function recordEventReceived(string $relayUrl): void
    {
        $this->healthStore->recordEventReceived($relayUrl);
    }

    public function setAuthRequired(string $relayUrl, bool $required = true): void
    {
        $this->healthStore->setAuthRequired($relayUrl, $required);
    }

    public function setAuthStatus(string $relayUrl, string $status): void
    {
        $this->healthStore->setAuthStatus($relayUrl, $status);
    }
}
