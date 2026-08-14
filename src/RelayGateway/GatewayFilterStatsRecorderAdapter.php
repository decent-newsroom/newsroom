<?php

declare(strict_types=1);

namespace App\RelayGateway;

use App\Service\Nostr\RelayFilterStatsStore;
use DecentNewsroom\RelayGatewayBundle\Contract\GatewayFilterStatsRecorderInterface;

final readonly class GatewayFilterStatsRecorderAdapter implements GatewayFilterStatsRecorderInterface
{
    public function __construct(private RelayFilterStatsStore $filterStatsStore)
    {
    }

    public function signature(array $filter): string
    {
        return $this->filterStatsStore->signature($filter);
    }

    public function recordRequest(string $relayUrl, string $signature): void
    {
        $this->filterStatsStore->recordRequest($relayUrl, $signature);
    }

    public function recordEose(string $relayUrl, string $signature, int $latencyMs, int $eventCount): void
    {
        $this->filterStatsStore->recordEose($relayUrl, $signature, $latencyMs, $eventCount);
    }

    public function recordTimeout(string $relayUrl, string $signature): void
    {
        $this->filterStatsStore->recordTimeout($relayUrl, $signature);
    }
}
