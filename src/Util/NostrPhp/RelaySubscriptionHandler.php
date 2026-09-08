<?php
declare(strict_types=1);

namespace App\Util\NostrPhp;

use DecentNewsroom\SigningBundle\Contract\RelayAuthSignerInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Message\CloseMessage;
use swentel\nostr\Relay\Relay;
use swentel\nostr\RelayResponse\RelayResponse;
use WebSocket\Client as WsClient;
use WebSocket\Message\Pong;
use WebSocket\Message\Text;

/**
 * Shared logic for handling Nostr relay subscriptions (both short-lived and persistent)
 * Extracts common WebSocket handling, AUTH, PING/PONG, and message parsing logic
 */
class RelaySubscriptionHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?RelayAuthSignerInterface $relayAuthSigner = null,
    ) {
    }

    /**
     * Send PING/PONG response to relay
     */
    public function handlePing(WsClient $client): void
    {
        $client->send(new Pong());
        $this->logger->debug('Received PING, sent PONG');
    }

    /**
     * Parse relay response from WebSocket text message
     * Returns null if message cannot be parsed
     */
    public function parseRelayResponse(Text $resp): ?RelayResponse
    {
        $content = $resp->getContent();
        $decoded = json_decode($content);

        if (!$decoded) {
            $this->logger->debug('Failed to decode message from relay', [
                'content_preview' => substr($content, 0, 100)
            ]);
            return null;
        }

        return RelayResponse::create($decoded);
    }

    /**
     * Handle a NIP-42 challenge for the user that initiated the request.
     *
     * Anonymous workers must not authenticate with generated keys. The request
     * is instead dropped when no user-scoped signing capability is available.
     */
    public function handleAuth(
        Relay $relay,
        WsClient $client,
        string $challenge,
        ?string $subjectPubkeyHex = null,
        int $timeoutSeconds = 3,
    ): bool
    {
        if (!$this->isHexPubkey($subjectPubkeyHex)) {
            $this->logger->info('Dropping relay request: no authenticated user is available for NIP-42 AUTH', [
                'relay' => $relay->getUrl(),
            ]);

            return false;
        }

        if ($this->relayAuthSigner === null || !$this->relayAuthSigner->supportsRelayAuth($subjectPubkeyHex)) {
            $this->logger->info('Dropping relay request: user has no relay AUTH signing capability', [
                'relay' => $relay->getUrl(),
                'pubkey' => substr($subjectPubkeyHex, 0, 8) . '...',
            ]);

            return false;
        }

        try {
            $signedEvent = $this->relayAuthSigner->signRelayAuth(
                $subjectPubkeyHex,
                $relay->getUrl(),
                $challenge,
                max(1, $timeoutSeconds),
            );
            if ($signedEvent === null) {
                $this->logger->warning('Dropping relay request: user relay AUTH signature was not obtained before timeout', [
                    'relay' => $relay->getUrl(),
                    'pubkey' => substr($subjectPubkeyHex, 0, 8) . '...',
                    'timeout_seconds' => $timeoutSeconds,
                ]);

                return false;
            }

            $client->text(json_encode(['AUTH', $signedEvent], JSON_THROW_ON_ERROR));
            $this->logger->debug('Sending user-signed NIP-42 AUTH to relay', [
                'relay' => $relay->getUrl(),
                'pubkey' => substr($subjectPubkeyHex, 0, 8) . '...',
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Dropping relay request: user relay AUTH signing failed', [
                'relay' => $relay->getUrl(),
                'pubkey' => substr($subjectPubkeyHex, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send CLOSE message to relay
     */
    public function sendClose(WsClient $client, string $subscriptionId): void
    {
        try {
            $close = new CloseMessage($subscriptionId);
            $client->text($close->generate());
            $this->logger->debug('Sent CLOSE message', [
                'subscription_id' => $subscriptionId
            ]);
        } catch (\Throwable $e) {
            $this->logger->debug('Failed to send CLOSE message', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Check if error is a timeout (normal for persistent subscriptions)
     */
    public function isTimeoutError(\Throwable $e): bool
    {
        $errorMessage = strtolower($e->getMessage());
        $errorClass = strtolower(get_class($e));

        return stripos($errorMessage, 'timeout') !== false ||
            stripos($errorClass, 'timeout') !== false ||
            stripos($errorMessage, 'connection operation') !== false;
    }

    /**
     * Check if error is a bad message error (can be ignored)
     */
    public function isBadMessageError(\Throwable $e): bool
    {
        $errorMessage = strtolower($e->getMessage());

        return stripos($errorMessage, 'bad msg') !== false ||
            stripos($errorMessage, 'unparseable') !== false ||
            stripos($errorMessage, 'invalid') !== false;
    }

    /**
     * Extract AUTH challenge from relay response
     */
    public function extractAuthChallenge($decoded): ?string
    {
        $decodedArray = is_array($decoded) ? $decoded : json_decode(json_encode($decoded), true);
        return $decodedArray[1] ?? null;
    }

    /**
     * Extract message from NOTICE/CLOSED response
     */
    public function extractMessage($decoded): string
    {
        $decodedArray = is_array($decoded) ? $decoded : json_decode(json_encode($decoded), true);
        return $decodedArray[1] ?? ($decodedArray[2] ?? 'no message');
    }

    /**
     * Check if NOTICE is an error
     */
    public function isErrorNotice(string $message): bool
    {
        return str_starts_with($message, 'ERROR:');
    }

    private function isHexPubkey(?string $pubkey): bool
    {
        return $pubkey !== null && strlen($pubkey) === 64 && ctype_xdigit($pubkey);
    }
}
