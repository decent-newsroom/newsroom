<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\StartRelayFeedMessage;
use App\Service\Nostr\RelayFeedBufferService;
use App\Service\Nostr\RelayRegistry;
use DecentNewsroom\NostrClientBundle\Contract\NostrClientFactoryInterface;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use nostriphant\NIP19\Bech32;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

use function Amp\delay;

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
        private readonly NostrClientFactoryInterface $nostrClientFactory,
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

            $relay = RelayUrl::fromString($connectUrl);
            $client = $this->nostrClientFactory->create();
            $subscriptionId = SubscriptionId::fromString('relay-feed-' . bin2hex(random_bytes(6)));
            $filter = Filter::fromArray([
                'kinds' => [30023],
                'since' => time() - self::LOOKBACK_SECONDS,
            ]);

            try {
                $client->connect($relay, $this->nostrClientFactory->createDefaultConnectionConfig());
                $client->subscribe(
                    $relay,
                    $filter,
                    new class(function (Event $event) use ($key, $relayUrl): void {
                        $eventId = $event->toArray()['id'] ?? null;
                        if (!is_string($eventId) || $this->buffer->alreadySeen($key, $eventId)) {
                            return;
                        }

                        $card = $this->extractCard($event, $relayUrl);
                        if ($card === null) {
                            return;
                        }

                        $this->buffer->markSeen($key, $eventId);
                        $this->buffer->pushToBuffer($key, $card);
                        $this->hub->publish(new Update('/relay-feed/' . $key, json_encode($card, JSON_THROW_ON_ERROR)));
                    }) implements EventHandlerInterface {
                        private readonly \Closure $onEvent;

                        public function __construct(callable $onEvent)
                        {
                            $this->onEvent = \Closure::fromCallable($onEvent);
                        }

                        public function handleEvent(Event $event, SubscriptionId $subscriptionId): void
                        {
                            ($this->onEvent)($event);
                        }

                        public function handleEose(SubscriptionId $subscriptionId): void
                        {
                        }

                        public function handleClosed(SubscriptionId $subscriptionId, string $message): void
                        {
                        }

                        public function handleNotice(RelayUrl $relayUrl, string $message): void
                        {
                        }
                    },
                    $subscriptionId,
                );

                $deadline = microtime(true) + self::WINDOW_SECONDS;
                while (microtime(true) < $deadline) {
                    delay(0.25);
                }

                $this->logger->info('[relay-feed] Window elapsed, closing subscription', ['key' => $key]);
            } finally {
                try {
                    $client->unsubscribe($relay, $subscriptionId);
                } finally {
                    $client->close();
                }
            }

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
    private function extractCard(Event $event, string $relayUrl): ?array
    {
        $eventData = $event->toArray();
        $id        = $eventData['id'] ?? null;
        $pubkey    = $eventData['pubkey'] ?? null;
        $createdAt = $eventData['created_at'] ?? 0;
        $tags      = is_array($eventData['tags'] ?? null) ? $eventData['tags'] : [];

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
            $npub = (static function (string $pubkey): string { return PublicKey::fromHex(strtolower(trim($pubkey)))?->toBech32() ?? throw new \InvalidArgumentException('Not a valid hex pubkey'); })((string) ((string) $pubkey));
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
