<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\GatewayWarmConnectionsMessage;
use App\Service\Nostr\RelayGatewayClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles async gateway warm-up dispatched after relay list warming.
 *
 * Writes a "warm" command to the relay:control Redis Stream so the
 * relay gateway opens and authenticates user-specific connections
 * to AUTH-gated relays.
 */
#[AsMessageHandler]
class GatewayWarmConnectionsHandler
{
    public function __construct(
        private readonly RelayGatewayClient $gatewayClient,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(GatewayWarmConnectionsMessage $message): void
    {
        $pubkey = $message->pubkeyHex;
        $relays = $message->authRelayUrls;

        if (empty($relays)) {
            $this->logger->debug('GatewayWarmConnectionsHandler: no AUTH relays to warm', [
                'pubkey' => substr($pubkey, 0, 16) . '...',
            ]);
            return;
        }

        $this->logger->info('GatewayWarmConnectionsHandler: warming user connections', [
            'pubkey' => substr($pubkey, 0, 16) . '...',
            'relay_count' => count($relays),
        ]);

        try {
            $this->gatewayClient->warmUserConnections($pubkey, $relays);
        } catch (\Throwable $e) {
            $this->logger->warning('GatewayWarmConnectionsHandler: failed to dispatch warm command', [
                'pubkey' => substr($pubkey, 0, 16) . '...',
                'error' => $e->getMessage(),
            ]);
            // Best-effort — don't rethrow
        }
    }
}

