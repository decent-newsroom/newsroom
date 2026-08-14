<?php

declare(strict_types=1);

namespace DecentNewsroom\RelayGatewayBundle\Contract;

interface GatewayHealthRecorderInterface
{
    public function recordSuccess(string $relayUrl, ?int $latencyMs = null): void;

    public function recordFailure(string $relayUrl): void;

    public function recordEventReceived(string $relayUrl): void;

    public function setAuthRequired(string $relayUrl, bool $required = true): void;

    public function setAuthStatus(string $relayUrl, string $status): void;
}