<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use Psr\Log\LoggerInterface;
use swentel\nostr\Encryption\Nip04;
use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;
use swentel\nostr\Message\EventMessage;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\Relay;
use swentel\nostr\RelayResponse\RelayResponseEvent;
use swentel\nostr\Sign\Sign;
use swentel\nostr\Subscription\Subscription;
use Symfony\Component\Uid\Uuid;

/**
 * Signs a NIP-42 AUTH event (kind:22242) server-side via a NIP-46 remote signer,
 * bypassing the browser Mercure roundtrip for remote-signer users.
 *
 * Flow:
 *   1. Build the unsigned kind:22242 AUTH event with the correct relay+challenge tags
 *   2. Encrypt a NIP-46 "sign_event" RPC request to the bunker (NIP-04)
 *   3. Publish the kind:24133 request event (signed by the ephemeral client key) to
 *      all configured signer relays
 *   4. Poll for a kind:24133 response from the bunker (up to $timeoutSeconds)
 *   5. Decrypt and return the signed kind:22242 event as an associative array
 *
 * Called from RelayGatewayCommand::handleAuthChallenge() when a stored NIP-46
 * session is found for the user.
 */
class Nip46AuthSigner
{
    public const DEFAULT_TIMEOUT = 15; // seconds

    private Key $keyUtil;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
        $this->keyUtil = new Key();
    }

    /**
     * Attempt to sign a NIP-42 AUTH event via the user's remote signer.
     *
     * @param string $userPubkeyHex   The user's Nostr pubkey (hex) — what goes in the event
     * @param string $relayUrl        The relay URL for the AUTH challenge tag
     * @param string $challenge       The relay's challenge string
     * @param array{clientPrivkeyHex: string, bunkerPubkeyHex: string, bunkerRelays: string[]} $session
     * @param int $timeoutSeconds     Max time to wait for bunker response
     *
     * @return array<string,mixed>|null  Signed kind:22242 event as array, or null on failure
     */
    public function signAuthEvent(
        string $userPubkeyHex,
        string $relayUrl,
        string $challenge,
        array $session,
        int $timeoutSeconds = self::DEFAULT_TIMEOUT,
    ): ?array {
        $clientPrivkey   = $session['clientPrivkeyHex'];
        $bunkerPubkey    = $session['bunkerPubkeyHex'];
        $bunkerRelays    = $session['bunkerRelays'];

        if (empty($clientPrivkey) || empty($bunkerPubkey) || empty($bunkerRelays)) {
            $this->logger->warning('Nip46AuthSigner: incomplete session data');
            return null;
        }

        $clientPubkey = $this->keyUtil->getPublicKey($clientPrivkey);

        // ── 1. Build unsigned AUTH event ──────────────────────────────────────
        $authEvent = [
            'kind'       => 22242,
            'created_at' => time(),
            'tags'       => [
                ['relay',     $relayUrl],
                ['challenge', $challenge],
            ],
            'content'    => '',
            'pubkey'     => $userPubkeyHex,
        ];

        // ── 2. Build and encrypt NIP-46 sign_event request ────────────────────
        $requestId = Uuid::v4()->toRfc4122();
        $rpcRequest = json_encode([
            'id'     => $requestId,
            'method' => 'sign_event',
            'params' => [json_encode($authEvent)],
        ]);

        try {
            $encryptedContent = Nip04::encrypt($rpcRequest, $clientPrivkey, $bunkerPubkey);
        } catch (\Throwable $e) {
            $this->logger->error('Nip46AuthSigner: NIP-04 encryption failed', ['error' => $e->getMessage()]);
            return null;
        }

        // ── 3. Build and sign the kind:24133 request event ────────────────────
        $requestEvent = new Event();
        $requestEvent->setKind(24133);
        $requestEvent->setContent($encryptedContent);
        $requestEvent->setTags([['p', $bunkerPubkey]]);

        try {
            (new Sign())->signEvent($requestEvent, $clientPrivkey);
        } catch (\Throwable $e) {
            $this->logger->error('Nip46AuthSigner: failed to sign request event', ['error' => $e->getMessage()]);
            return null;
        }

        $this->logger->info('Nip46AuthSigner: sending sign_event request via NIP-46', [
            'request_id'   => $requestId,
            'relay'        => $relayUrl,
            'bunker_relay_count' => count($bunkerRelays),
        ]);

        // ── 4. Publish request and poll for response ───────────────────────────
        $connections = [];
        foreach ($bunkerRelays as $bunkerRelay) {
            try {
                $relay = new Relay($bunkerRelay);
                $relay->connect();
                $client = $relay->getClient();
                $client->setTimeout(0);

                // Publish kind:24133 request
                $eventMsg = new EventMessage($requestEvent);
                $client->text($eventMsg->generate());

                // Subscribe for kind:24133 from the bunker
                $sub       = new Subscription();
                $subId     = $sub->setId();
                $reqMsg    = json_encode(['REQ', $subId, [
                    'kinds'   => [24133],
                    'authors' => [$bunkerPubkey],
                    '#p'      => [$clientPubkey],
                    'since'   => time() - 5,
                ]]);
                $client->text($reqMsg);

                $connections[] = ['client' => $client, 'relay' => $relay, 'subId' => $subId];

            } catch (\Throwable $e) {
                $this->logger->warning('Nip46AuthSigner: failed to connect to bunker relay', [
                    'relay' => $bunkerRelay,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (empty($connections)) {
            $this->logger->error('Nip46AuthSigner: no bunker relay connections established');
            return null;
        }

        // ── 5. Poll for response ──────────────────────────────────────────────
        $deadline = time() + $timeoutSeconds;

        while (time() < $deadline) {
            foreach ($connections as $conn) {
                /** @var \WebSocket\Client $wsClient */
                $wsClient = $conn['client'];
                try {
                    $raw = $wsClient->receive();
                    if ($raw === null) {
                        continue;
                    }
                    $message = is_object($raw) ? (string) $raw : (string) $raw;
                    $parsed  = json_decode($message, true);

                    if (!is_array($parsed) || ($parsed[0] ?? '') !== 'EVENT') {
                        continue;
                    }

                    $event = $parsed[2] ?? null;
                    if (!is_array($event) || ($event['kind'] ?? 0) !== 24133) {
                        continue;
                    }

                    // Decrypt the response
                    $decrypted = Nip04::decrypt($event['content'], $clientPrivkey, $bunkerPubkey);
                    $response  = json_decode($decrypted, true);

                    if (!is_array($response) || ($response['id'] ?? '') !== $requestId) {
                        continue;
                    }

                    if (!empty($response['error'])) {
                        $this->logger->warning('Nip46AuthSigner: bunker returned error', [
                            'error' => $response['error'],
                        ]);
                        $this->closeConnections($connections);
                        return null;
                    }

                    $signedEventJson = $response['result'] ?? null;
                    if (!is_string($signedEventJson)) {
                        continue;
                    }

                    $signedEvent = json_decode($signedEventJson, true);
                    if (!is_array($signedEvent) || empty($signedEvent['sig'])) {
                        $this->logger->warning('Nip46AuthSigner: invalid signed event in response');
                        $this->closeConnections($connections);
                        return null;
                    }

                    $this->logger->info('Nip46AuthSigner: received signed AUTH event from bunker', [
                        'request_id'  => $requestId,
                        'relay'       => $relayUrl,
                        'event_id'    => substr($signedEvent['id'] ?? '', 0, 16),
                    ]);

                    $this->closeConnections($connections);
                    return $signedEvent;

                } catch (\Throwable $e) {
                    if (str_contains($e->getMessage(), 'timeout') || str_contains($e->getMessage(), 'timed out')) {
                        // Non-blocking timeout — try next connection
                        continue;
                    }
                    $this->logger->debug('Nip46AuthSigner: receive error', ['error' => $e->getMessage()]);
                }
            }
            usleep(50000); // 50ms pause between sweeps
        }

        $this->logger->warning('Nip46AuthSigner: timed out waiting for bunker response', [
            'request_id' => $requestId,
            'timeout'    => $timeoutSeconds,
        ]);
        $this->closeConnections($connections);
        return null;
    }

    /** @param array<array{client: \WebSocket\Client, relay: Relay}> $connections */
    private function closeConnections(array $connections): void
    {
        foreach ($connections as $conn) {
            try { $conn['relay']->disconnect(); } catch (\Throwable) {}
        }
    }
}


