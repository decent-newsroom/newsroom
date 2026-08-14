<?php

declare(strict_types=1);

namespace App\RelayGateway;

use App\Service\Nostr\RelayUserActivityStore;
use DecentNewsroom\RelayGatewayBundle\Contract\GatewayActivityRecorderInterface;

final readonly class GatewayActivityRecorderAdapter implements GatewayActivityRecorderInterface
{
    public function __construct(private RelayUserActivityStore $activityStore)
    {
    }

    public function recordAuth(string $pubkeyHex, string $relayUrl, string $method, string $status, ?string $message = null): void
    {
        $this->activityStore->recordAuth($pubkeyHex, $relayUrl, $method, $status, $message);
    }

    public function recordPublish(string $pubkeyHex, string $relayUrl, bool $accepted, ?string $message = null): void
    {
        $this->activityStore->recordPublish($pubkeyHex, $relayUrl, null, $accepted, $message);
    }
}
