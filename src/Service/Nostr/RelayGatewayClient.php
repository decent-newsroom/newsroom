<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use DecentNewsroom\RelayGatewayBundle\Service\RelayGatewayClient as BundleRelayGatewayClient;

/**
 * Backward-compatible app service ID for code that still type-hints the
 * historical App namespace while the implementation lives in RelayGatewayBundle.
 */
class RelayGatewayClient extends BundleRelayGatewayClient
{
}
