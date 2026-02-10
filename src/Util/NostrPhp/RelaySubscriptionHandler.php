<?php
declare(strict_types=1);

namespace App\Util\NostrPhp;

use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use swentel\nostr\Message\AuthMessage;
use swentel\nostr\Message\CloseMessage;
use swentel\nostr\Nip42\AuthEvent;
use swentel\nostr\Relay\Relay;
use swentel\nostr\RelayResponse\RelayResponse;
use swentel\nostr\Sign\Sign;
use WebSocket\Client as WsClient;
use WebSocket\Message\Pong;
use WebSocket\Message\Text;

/**
 * Shared logic for handling Nostr relay subscriptions (both short-lived and persistent)
 * Extracts common WebSocket handling, AUTH, PING/PONG, and message parsing logic
 */
class RelaySubscriptionHandler
{
    private string $nsec;

    public function __construct(
        private readonly LoggerInterface $logger
    ) {
        // Create an ephemeral key for NIP-42 auth
        $key = new Key();
        $this->nsec = $key->generatePrivateKey();
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
     * Handle NIP-42 AUTH challenge
     * Automatically signs and sends AUTH message
     */
    public function handleAuth(Relay $relay, WsClient $client, string $challenge): void
    {
        try {
            $authEvent = new AuthEvent($relay->getUrl(), $challenge);
            (new Sign())->signEvent($authEvent, $this->nsec);
            $authMsg = new AuthMessage($authEvent);

            $this->logger->debug('Sending NIP-42 AUTH to relay', [
                'relay' => $relay->getUrl()
            ]);

            $client->text($authMsg->generate());
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to send AUTH, continuing anyway', [
                'relay' => $relay->getUrl(),
                'error' => $e->getMessage()
            ]);
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
}

