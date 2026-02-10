<?php
declare(strict_types=1);

namespace App\Util\NostrPhp;

use Psr\Log\LoggerInterface;
use swentel\nostr\MessageInterface;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Relay\RelaySet;
use swentel\nostr\RelayResponse\RelayResponse;
use swentel\nostr\RequestInterface;
use WebSocket\Client as WsClient;
use WebSocket\Message\Text;

/**
 * A deterministic "stop on first matching EVENT" request.
 * Implements RequestInterface so we can DI-substitute it for the vendor Request.
 * Uses RelaySubscriptionHandler for common relay communication logic.
 */
final class TweakedRequest implements RequestInterface
{
    private RelaySet $relays;
    private string $payload;
    private array $responses = [];
    private int $timeout = 15;
    private RelaySubscriptionHandler $handler;

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

        // Use shared handler for common relay logic
        $this->handler = new RelaySubscriptionHandler($logger);
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
                $client->setTimeout($this->timeout);

                // Send subscription payload
                $client->text($this->payload);

                // Loop until: first match, EOSE/CLOSED/ERROR, or socket ends
                while ($resp = $client->receive()) {
                    // Handle PING/PONG using shared handler
                    if ($resp instanceof \WebSocket\Message\Ping) {
                        $this->handler->handlePing($client);
                        continue;
                    }
                    if (!$resp instanceof Text) {
                        continue;
                    }

                    // Parse relay response using shared handler
                    $relayResponse = $this->handler->parseRelayResponse($resp);
                    if (!$relayResponse) {
                        continue;
                    }

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
                                $this->handler->sendClose($client, $sub);
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
                            $this->handler->sendClose($client, $sub);
                        }
                        $relay->disconnect();
                        break;
                    }

                    if ($relayResponse->type === 'NOTICE') {
                        $message = $this->handler->extractMessage(json_decode($resp->getContent()));
                        if ($this->handler->isErrorNotice($message)) {
                            $relay->disconnect();
                            break;
                        }
                    }

                    if ($relayResponse->type === 'CLOSED') {
                        $relay->disconnect();
                        break;
                    }

                    // NIP-42: if relay requests AUTH, perform it once, then continue.
                    if ($relayResponse->type === 'OK' && isset($relayResponse->message) && str_starts_with($relayResponse->message, 'auth-required:')) {
                        $this->handler->handleAuth($relay, $client, $_SESSION['challenge'] ?? '');
                        // After AUTH, re-send the original payload
                        $client->text($this->payload);
                        // continue loop
                    }

                    // NIP-42: handle AUTH challenge for subscriptions
                    if ($relayResponse->type === 'AUTH') {
                        $challenge = $this->handler->extractAuthChallenge(json_decode($resp->getContent()));
                        if ($challenge) {
                            $_SESSION['challenge'] = $challenge;
                            $this->handler->handleAuth($relay, $client, $challenge);
                        }
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

    public function setTimeout(float|int $timeout): static
    {
        $this->timeout = (int) $timeout;
        return $this;
    }
}
