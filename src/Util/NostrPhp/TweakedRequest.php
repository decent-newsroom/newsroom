<?php
declare(strict_types=1);

namespace App\Util\NostrPhp;

use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use swentel\nostr\Message\AuthMessage;
use swentel\nostr\Message\CloseMessage;
use swentel\nostr\MessageInterface;
use swentel\nostr\Nip42\AuthEvent;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Relay\RelaySet;
use swentel\nostr\RelayResponse\RelayResponse;
use swentel\nostr\RequestInterface;
use swentel\nostr\Sign\Sign;
use WebSocket\Client as WsClient;
use WebSocket\Message\Pong;
use WebSocket\Message\Text;

/**
 * A deterministic "stop on first matching EVENT" request.
 * Implements RequestInterface so we can DI-substitute it for the vendor Request.
 */
final class TweakedRequest implements RequestInterface
{
    private $nsec;
    private RelaySet $relays;
    private string $payload;
    private array $responses = [];

    /** Optional: when set, CLOSE & disconnect immediately once this id arrives */
    private ?string $stopOnEventId = null;

    public function __construct(Relay|RelaySet $relay, MessageInterface $message, private readonly LoggerInterface $logger)
    {
        if ($relay instanceof RelaySet) {
            $this->relays = $relay;
        } else {
            $set = new RelaySet();
            $set->setRelays([$relay]);
            $this->relays = $set;
        }
        $this->payload = $message->generate();

        // Create an ephemeral key for NIP-42 auth
        $key = new Key();
        $this->nsec = $key->generatePrivateKey();
    }

    public function stopOnEventId(?string $hexId): self
    {
        $this->stopOnEventId = $hexId;
        return $this;
    }

    /** @return array<string, array|RelayResponse> */
    public function send(): array
    {
        $result = [];
        foreach ($this->relays->getRelays() as $relay) {
            $this->responses = []; // reset per relay
            try {
                if (!$relay->isConnected()) {
                    $relay->connect();
                }

                $client = $relay->getClient();
                $client->setTimeout(15); // seconds per receive call (keep it small if you want responsiveness)

                // Send subscription payload
                $client->text($this->payload);

                // Loop until: first match, EOSE/CLOSED/ERROR, or socket ends
                while ($resp = $client->receive()) {
                    if ($resp instanceof \WebSocket\Message\Ping) {
                        $client->send(new Pong());
                        continue;
                    }
                    if (!$resp instanceof Text) {
                        continue;
                    }

                    $decoded = json_decode($resp->getContent());
                    $relayResponse = RelayResponse::create($decoded);
                    $this->responses[] = $relayResponse;

                    // Early exit on matching EVENT
                    if ($relayResponse->type === 'EVENT') {
                        // Safest: decode again to array to grab [ "EVENT", subId, event ]
                        $raw = json_decode($resp->getContent(), true);
                        $sub = $raw[1] ?? null;
                        $evt = $raw[2] ?? null;
                        $evtId = is_array($evt) ? ($evt['id'] ?? null) : null;

                        if ($this->stopOnEventId !== null && $evtId === $this->stopOnEventId) {
                            if ($sub) {
                                $this->sendClose($client, $sub);
                            }
                            $relay->disconnect();
                            $result[$relay->getUrl()] = $this->responses;
                            // stop the outer foreach too (we're done)
                            return $result;
                        }
                    }

                    // Tear-down conditions
                    if ($relayResponse->type === 'EOSE') {
                        $sub = $relayResponse->subscriptionId ?? null;
                        if ($sub) {
                            $this->sendClose($client, $sub);
                        }
                        $relay->disconnect();
                        break;
                    }

                    if ($relayResponse->type === 'NOTICE' && str_starts_with($relayResponse->message ?? '', 'ERROR:')) {
                        $relay->disconnect();
                        break;
                    }

                    if ($relayResponse->type === 'CLOSED') {
                        $relay->disconnect();
                        break;
                    }

                    // NIP-42: if relay requests AUTH, perform it once, then continue.
                    if ($relayResponse->type === 'OK' && isset($relayResponse->message) && str_starts_with($relayResponse->message, 'auth-required:')) {
                        $this->performAuth($relay, $client);
                        // After AUTH, re-send the original payload
                        $client->text($this->payload);
                        // continue loop
                    }

                    // NIP-42: handle AUTH challenge for subscriptions
                    if ($relayResponse->type === 'AUTH') {
                        $raw = json_decode($resp->getContent(), true);
                        $_SESSION['challenge'] = $raw[1] ?? '';
                        $this->logger->debug('Received AUTH challenge from relay: ' . $relay->getUrl());
                        $this->performAuth($relay, $client);
                        // continue loop, relay should now respond to the subscription
                    }
                }

                // Save what we got for this relay
                $result[$relay->getUrl()] = $this->responses;
            } catch (\Throwable $e) {
                $result[$relay->getUrl()][] = ['ERROR', '', false, $e->getMessage()];
                // best-effort disconnect
                try { $relay->disconnect(); } catch (\Throwable) {}
            }
        }

        return $result;
    }

    private function sendClose(WsClient $client, string $subscriptionId): void
    {
        try {
            $close = new CloseMessage($subscriptionId);
            $client->text($close->generate());
        } catch (\Throwable) {}
    }

    /** Very lightweight NIP-42 auth flow: sign challenge and send AUTH + resume. */
    private function performAuth(Relay $relay, WsClient $client): void
    {
        if (!isset($_SESSION['challenge'])) {
            return;
        }
        try {
            $authEvent = new AuthEvent($relay->getUrl(), $_SESSION['challenge']);
            (new Sign())->signEvent($authEvent, $this->nsec);
            $authMsg = new AuthMessage($authEvent);
            $this->logger->debug('Sending NIP-42 AUTH to relay: ' . $relay->getUrl());
            $client->text($authMsg->generate());
        } catch (\Throwable) {
            // ignore and continue; some relays won’t require it
        }
    }
}
