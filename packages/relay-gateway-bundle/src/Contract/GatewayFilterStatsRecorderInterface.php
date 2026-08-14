<?php

declare(strict_types=1);

namespace DecentNewsroom\RelayGatewayBundle\Contract;

interface GatewayFilterStatsRecorderInterface
{
    /**
     * @param array<string,mixed> $filter
     */
    public function signature(array $filter): string;

    public function recordRequest(string $relayUrl, string $signature): void;

    public function recordEose(string $relayUrl, string $signature, int $latencyMs, int $eventCount): void;

    public function recordTimeout(string $relayUrl, string $signature): void;
}