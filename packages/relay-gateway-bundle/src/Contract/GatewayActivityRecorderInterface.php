<?php

declare(strict_types=1);

namespace DecentNewsroom\RelayGatewayBundle\Contract;

interface GatewayActivityRecorderInterface
{
    public const TYPE_AUTH = 'auth';
    public const TYPE_PUBLISH = 'publish';

    public const STATUS_OK = 'ok';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PENDING = 'pending';

    public function recordAuth(string $pubkeyHex, string $relayUrl, string $method, string $status, ?string $message = null): void;

    public function recordPublish(string $pubkeyHex, string $relayUrl, bool $accepted, ?string $message = null): void;
}