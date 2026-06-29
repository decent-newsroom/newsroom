<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\StartRelayFeedMessage;
use App\Service\Nostr\RelayFeedBufferService;
use App\Service\Nostr\RelayRegistry;
use App\Util\NostrPhp\RelaySubscriptionHandler;
use App\Util\NostrKeyUtil;
use nostriphant\NIP19\Bech32;
use Psr\Log\LoggerInterface;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Subscription\Subscription;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use WebSocket\Message\Ping;
use WebSocket\Message\Text;

/**
 * Opens a time-bounded WebSocket subscription to an arbitrary relay
 * and streams raw kind-30023 article metadata to the browser via Mercure.
 *
 * Intentionally bypasses every ingestion/QA pipeline — the raw card
 * (title, summary, image) is all that is extracted. Full ingestion is
 * triggered only when the user clicks to read an article.
 *
 * Runtime: ~5 minutes per invocation.  Re-dispatches itself while the feed
 * is still actively watched (Redis active flag set by the controller).
 */
#[AsMessageHandler]
final class StartRelayFeedHandler
{
    /** Seconds to run before re-dispatching (must be well under redeliver_timeout). */
    private const WINDOW_SECONDS = 270; // 4.5 minutes — safe for async_low_priority 600 s timeout

    /** Seconds to look back on the relay's event history on initial connect. */
    private const LOOKBACK_SECONDS = 86400; // 24 hours

    public function __construct(
        private readonly RelayFeedBufferService $buffer,
        private readonly HubInterface $hub,
        private readonly MessageBusInterface $bus,
        private readonly RelayRegistry $relayRegistry,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(StartRelayFeedMessage $message): void
    {
        $relayUrl = $message->relayUrl;
        $key      = $message->relayKey;

        if (!$this->buffer->isActive($key)) {
            $this->logger->info('[relay-feed] No active viewers, skipping subscription', ['key' => $key]);
            return;
        }

        $this->logger->info('[relay-feed] Starting subscription window', [
            'relay' => $relayUrl,
            'key'   => $key,
        ]);

        try {
            // If the user selected the project relay's public hostname, connect via
            // the internal Docker URL (LOCAL) to avoid an unnecessary external round-trip.
            $connectUrl = $this->relayRegistry->resolveToLocalUrl($relayUrl);

            $relay  = new Relay($connectUrl);
            $relay->connect();

            $client = $relay->getClient();
            $client->setTimeout(20);

            $subscription   = new Subscription();
            $subscriptionId = $subscription->setId();

            $filter = new Filter();
            $filter->setKinds([30023]);
            $filter->setSince(time() - self::LOOKBACK_SECONDS);

            $reqMsg = new RequestMessage($subscriptionId, [$filter]);
            $client->text($reqMsg->generate());

            $handler   = new RelaySubscriptionHandler($this->logger);
            $startTime = time();

            while (true) {
                if ((time() - $startTime) >= self::WINDOW_SECONDS) {
                    $this->logger->info('[relay-feed] Window elapsed, closing subscription', ['key' => $key]);
                    break;
                }

                try {
                    $resp = $client->receive();
                } catch (\Throwable $e) {
                    if ($handler->isTimeoutError($e)) {
                        continue;
                    }
                    if ($handler->isBadMessageError($e)) {
                        continue;
                    }
                    throw $e;
                }

                if ($resp instanceof Ping) {
                    $handler->handlePing($client);
                    continue;
                }

                if (!$resp instanceof Text) {
                    continue;
                }

                $content = $resp->getContent();
                $decoded = json_decode($content);

                if (!$decoded || !is_array($decoded)) {
                    continue;
                }

                $msgType = $decoded[0] ?? null;

                if ($msgType === 'AUTH' && count($decoded) >= 2) {
                    $handler->handleAuth($relay, $client, (string) $decoded[1]);
                    continue;
                }

                if ($msgType !== 'EVENT') {
                    continue;
                }

                $event = $decoded[2] ?? null;
                if (!is_object($event)) {
                    continue;
                }

                $eventId = $event->id ?? null;
                if (!$eventId || $this->buffer->alreadySeen($key, (string) $eventId)) {
                    continue;
                }

                $card = $this->extractCard($event, $relayUrl);
                if ($card === null) {
                    continue;
                }

                $this->buffer->markSeen($key, (string) $eventId);
                $this->buffer->pushToBuffer($key, $card);

                // Publish to Mercure as a public (non-private) update.
                // With the hub's `anonymous` directive enabled, unauthenticated
                // EventSource connections can receive these events without a JWT.
                $this->hub->publish(new Update(
                    '/relay-feed/' . $key,
                    json_encode($card),
                ));
            }

            $handler->sendClose($client, $subscriptionId);
            $client->close();

        } catch (\Throwable $e) {
            $this->logger->error('[relay-feed] Subscription error', [
                'relay' => $relayUrl,
                'key'   => $key,
                'error' => $e->getMessage(),
            ]);
        }

        // Re-dispatch if someone is still watching
        if ($this->buffer->isActive($key)) {
            $this->logger->info('[relay-feed] Re-dispatching for continued watching', ['key' => $key]);
            $this->bus->dispatch($message);
        } else {
            $this->logger->info('[relay-feed] Feed no longer active, not re-dispatching', ['key' => $key]);
        }
    }

    /**
     * Extract a minimal article card from a raw Nostr event.
     * Returns null if required fields (id, pubkey, d-tag) are missing.
     *
     * @return array{id:string, pubkey:string, npub:string, created_at:int, title:string, summary:string, image:string, d_tag:string, naddr:string, relay:string}|null
     */
    private function extractCard(object $event, string $relayUrl): ?array
    {
        $id        = $event->id      ?? null;
        $pubkey    = $event->pubkey  ?? null;
        $createdAt = $event->created_at ?? 0;
        $tags      = is_array($event->tags ?? null) ? $event->tags : [];

        if (!$id || !$pubkey) {
            return null;
        }

        $title   = '';
        $summary = '';
        $image   = '';
        $dTag    = '';

        foreach ($tags as $tag) {
            if (!is_array($tag) || !isset($tag[0])) {
                continue;
            }
            match ($tag[0]) {
                'title'   => $title   = (string) ($tag[1] ?? ''),
                'summary' => $summary = (string) ($tag[1] ?? ''),
                'image'   => $image   = (string) ($tag[1] ?? ''),
                'd'       => $dTag    = (string) ($tag[1] ?? ''),
                default   => null,
            };
        }

        if ($dTag === '') {
            return null;
        }

        // Build an naddr so the card can link directly to the article route.
        $naddr = '';
        try {
            $naddr = (string) Bech32::naddr(
                kind: 30023,
                pubkey: $pubkey,
                identifier: $dTag,
                relays: [$relayUrl],
            );
        } catch (\Throwable) {
            // Non-fatal; clicking the card will just open a 404 that triggers async fetch
        }

        $npub = '';
        try {
            $npub = NostrKeyUtil::hexToNpub((string) $pubkey);
        } catch (\Throwable) {
            // Non-fatal; UI falls back to short hex pubkey
        }

        return [
            'id'         => (string) $id,
            'pubkey'     => (string) $pubkey,
            'npub'       => $npub,
            'created_at' => (int) $createdAt,
            'title'      => $title,
            'summary'    => $summary,
            'image'      => $image,
            'd_tag'      => $dTag,
            'naddr'      => $naddr,
            'relay'      => $relayUrl,
        ];
    }
}
