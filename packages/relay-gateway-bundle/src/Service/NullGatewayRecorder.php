<?php

declare(strict_types=1);

namespace DecentNewsroom\RelayGatewayBundle\Service;

use DecentNewsroom\RelayGatewayBundle\Contract\GatewayActivityRecorderInterface;
use DecentNewsroom\RelayGatewayBundle\Contract\GatewayFilterStatsRecorderInterface;
use DecentNewsroom\RelayGatewayBundle\Contract\GatewayHealthRecorderInterface;
use DecentNewsroom\RelayGatewayBundle\Contract\RelayUrlResolverInterface;

final class NullGatewayRecorder implements GatewayHealthRecorderInterface, GatewayActivityRecorderInterface, GatewayFilterStatsRecorderInterface
{
    public function recordSuccess(string $relayUrl, ?int $latencyMs = null): void {}

    public function recordFailure(string $relayUrl): void {}

    public function recordEventReceived(string $relayUrl): void {}

    public function setAuthRequired(string $relayUrl, bool $required = true): void {}

    public function setAuthStatus(string $relayUrl, string $status): void {}

    public function recordAuth(string $pubkeyHex, string $relayUrl, string $method, string $status, ?string $message = null): void {}

    public function recordPublish(string $pubkeyHex, string $relayUrl, bool $accepted, ?string $message = null): void {}

    public function signature(array $filter): string
    {
        ksort($filter);

        return hash('sha256', json_encode($filter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: serialize($filter));
    }

    public function recordRequest(string $relayUrl, string $signature): void {}

    public function recordEose(string $relayUrl, string $signature, int $latencyMs, int $eventCount): void {}

    public function recordTimeout(string $relayUrl, string $signature): void {}
}