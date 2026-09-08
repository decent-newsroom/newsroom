<?php

declare(strict_types=1);

namespace App\Service\Essayist;

use DecentNewsroom\NostrClientBundle\Contract\NostrClientFactoryInterface;
use Innis\Nostr\Client\Domain\Service\AuthChallengeHandlerInterface;
use Innis\Nostr\Core\Application\Port\EventHandlerInterface;
use Innis\Nostr\Core\Domain\Entity\Event;
use Innis\Nostr\Core\Domain\Entity\Filter;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\RelayUrl;
use Innis\Nostr\Core\Domain\ValueObject\Protocol\SubscriptionId;
use nostriphant\NIP19\Bech32;
use Psr\Log\LoggerInterface;

use function Amp\delay;

/**
 * Fetches kind:30023 articles directly from the internal strfry-essayist relay.
 *
 * Returns lightweight stdClass cards that are compatible with the CardList /
 * Card Twig components (same property shape as the Article entity).
 *
 * The relay is reached via the internal Docker network URL only — it is never
 * exposed as a public endpoint. If the relay is not reachable (e.g. the
 * `essayist` profile is not active), the service returns an empty array and
 * logs a warning.
 */
final class EssayistFeedService
{
    /** Seconds to wait for the relay to send EOSE before giving up. */
    private const IDLE_TIMEOUT = 5;

    public function __construct(
        private readonly string $internalRelayUrl,
        private readonly LoggerInterface $logger,
        private readonly NostrClientFactoryInterface $nostrClientFactory,
    ) {
    }

    /**
     * Fetch the latest kind:30023 articles from strfry-essayist.
     *
     * @return object[] Array of stdClass cards, sorted by createdAt descending.
     *                  Each card has: pubkey, slug, title, summary, image, kind,
     *                  topics, createdAt (\DateTimeImmutable), publishedAt (\DateTimeImmutable|null)
     */
    /**
     * Return the internal relay URL this service connects to.
     * Used by the controller to derive the Mercure relay-feed key.
     */
    public function getRelayUrl(): string
    {
        return $this->internalRelayUrl;
    }

    public function fetchLatest(int $limit = 50): array
    {
        $filter = Filter::fromArray([
            'kinds' => [30023],
            'limit' => $limit,
        ]);

        return $this->doFetch($filter);
    }

    /**
     * Fetch kind:30023 articles from strfry-essayist filtered to the given author pubkeys.
     *
     * @param  string[] $pubkeys Hex pubkeys
     * @return object[]
     */
    public function fetchByPubkeys(array $pubkeys, int $limit = 50): array
    {
        if (empty($pubkeys)) {
            return [];
        }

        // The relay authors filter can be large; chunk to avoid protocol limits
        $pubkeys = array_values(array_unique($pubkeys));

        try {
            $authors = [];
            foreach ($pubkeys as $pubkey) {
                if (!is_string($pubkey)) {
                    throw new \InvalidArgumentException('Author pubkeys must be strings');
                }

                $author = str_starts_with($pubkey, 'npub')
                    ? PublicKey::fromBech32($pubkey)
                    : PublicKey::fromHex($pubkey);

                if (null === $author) {
                    throw new \InvalidArgumentException('Author pubkeys must be valid hex or npub values');
                }

                $authors[] = $author->toHex();
            }

            if (count($authors) !== count(array_unique($authors))) {
                throw new \InvalidArgumentException('There are duplicate author pubkeys in the filter');
            }

            $filter = Filter::fromArray([
                'kinds' => [30023],
                'authors' => $authors,
                'limit' => $limit,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('EssayistFeedService: invalid pubkeys for author filter', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        return $this->doFetch($filter);
    }

    /**
     * Fetch kind:30023 articles from strfry-essayist filtered by topic hashtags (#t tags).
     *
     * @param  string[] $hashtags Topic strings (without '#')
     * @return object[]
     */
    public function fetchByTopics(array $hashtags, int $limit = 50): array
    {
        if (empty($hashtags)) {
            return [];
        }

        $hashtags = array_values(array_unique(array_map('strtolower', $hashtags)));

        try {
            $filter = Filter::fromArray([
                'kinds' => [30023],
                '#t' => $hashtags,
                'limit' => $limit,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('EssayistFeedService: invalid hashtags for tag filter', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        return $this->doFetch($filter);
    }

    /**
     * Fetch kind:30023 articles from strfry-essayist filtered by `d` tag (slug) values.
     *
     * After fetching, only cards whose full coordinate (pubkey:slug) is in the
     * provided $allowedCoordinates set are returned, so articles with matching
     * slugs by different authors are not included unless their coordinate was
     * explicitly requested.
     *
     * @param  string[] $dTags              Slug / d-tag values (without pubkey prefix)
     * @param  string[] $allowedCoordinates Set of "pubkey:slug" strings used for post-filtering
     * @return object[]
     */
    public function fetchByDTags(array $dTags, array $allowedCoordinates = [], int $limit = 50): array
    {
        if (empty($dTags)) {
            return [];
        }

        $dTags = array_values(array_unique($dTags));

        try {
            $filter = Filter::fromArray([
                'kinds' => [30023],
                '#d' => $dTags,
                'limit' => $limit,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('EssayistFeedService: invalid d-tags for tag filter', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $cards = $this->doFetch($filter);

        // Narrow results to the exact (pubkey, d-tag) pairs we asked for.
        if (!empty($allowedCoordinates)) {
            $allowed = array_flip($allowedCoordinates);
            $cards = array_values(array_filter(
                $cards,
                fn (object $c): bool => isset($allowed[$c->pubkey . ':' . $c->slug])
            ));
        }

        return $cards;
    }

    /**
     * Execute a relay REQ with the given filter and return the resulting cards.
     *
     * @return object[]
     */
    private function doFetch(Filter $filter): array
    {
        if (empty($this->internalRelayUrl)) {
            $this->logger->warning('EssayistFeedService: internal relay URL not configured');
            return [];
        }

        return $this->doFetchFromRelay($filter, $this->internalRelayUrl);
    }

    /**
     * Execute a relay REQ against a single relay and return cards.
     *
     * @return object[]
     */
    private function doFetchFromRelay(Filter $filter, string $relayUrl): array
    {
        try {
            $relay = RelayUrl::fromString($relayUrl);
            if (null === $relay) {
                throw new \InvalidArgumentException('Invalid relay URL');
            }

            $client         = $this->nostrClientFactory->create();
            $subscriptionId = SubscriptionId::fromString('essayist-' . bin2hex(random_bytes(6)));
            $cards          = [];
            $eose           = false;
            $authRequired   = false;
            $lastMessage    = 0.0;
            $subscribed     = false;

            try {
                // The feed is anonymous: mark AUTH-gated relays as unavailable rather
                // than attempting to manufacture an identity for NIP-42.
                $client->setAuthHandler(new class(
                    function () use (&$authRequired, &$eose, &$lastMessage, $relayUrl): void {
                        $authRequired = true;
                        $eose = true;
                        $lastMessage = microtime(true);
                        $this->logger->info('EssayistFeedService: dropping anonymous AUTH-gated relay request', [
                            'relay' => $relayUrl,
                        ]);
                    }
                ) implements AuthChallengeHandlerInterface {
                    private readonly \Closure $onChallenge;

                    public function __construct(callable $onChallenge)
                    {
                        $this->onChallenge = \Closure::fromCallable($onChallenge);
                    }

                    public function handleAuthChallenge(RelayUrl $relayUrl, string $challenge): ?Event
                    {
                        ($this->onChallenge)();

                        return null;
                    }
                });

                $client->connect($relay, $this->nostrClientFactory->createDefaultConnectionConfig());
                $subscribed = true;
                $client->subscribe(
                    $relay,
                    $filter,
                    new class(
                        function (Event $event) use (&$cards, &$authRequired, &$lastMessage): void {
                            if ($authRequired) {
                                return;
                            }

                            $lastMessage = microtime(true);
                            $card = $this->buildCard($event);
                            if (null !== $card) {
                                $cards[] = $card;
                            }
                        },
                        function () use (&$eose, &$lastMessage, &$cards): void {
                            $lastMessage = microtime(true);
                            $eose = true;
                            $this->logger->debug('EssayistFeedService: EOSE received', [
                                'received' => count($cards),
                            ]);
                        },
                        function (string $message) use (&$eose, &$lastMessage): void {
                            $lastMessage = microtime(true);
                            $eose = true;
                            $this->logger->warning('EssayistFeedService: relay closed subscription', [
                                'message' => $message,
                            ]);
                        },
                        function () use (&$lastMessage): void {
                            $lastMessage = microtime(true);
                        },
                    ) implements EventHandlerInterface {
                        private readonly \Closure $onEvent;
                        private readonly \Closure $onEose;
                        private readonly \Closure $onClosed;
                        private readonly \Closure $onNotice;

                        public function __construct(
                            callable $onEvent,
                            callable $onEose,
                            callable $onClosed,
                            callable $onNotice,
                        ) {
                            $this->onEvent = \Closure::fromCallable($onEvent);
                            $this->onEose = \Closure::fromCallable($onEose);
                            $this->onClosed = \Closure::fromCallable($onClosed);
                            $this->onNotice = \Closure::fromCallable($onNotice);
                        }

                        public function handleEvent(Event $event, SubscriptionId $subscriptionId): void
                        {
                            ($this->onEvent)($event);
                        }

                        public function handleEose(SubscriptionId $subscriptionId): void
                        {
                            ($this->onEose)();
                        }

                        public function handleClosed(SubscriptionId $subscriptionId, string $message): void
                        {
                            ($this->onClosed)($message);
                        }

                        public function handleNotice(RelayUrl $relayUrl, string $message): void
                        {
                            ($this->onNotice)();
                        }
                    },
                    $subscriptionId,
                );
                $lastMessage = microtime(true);

                while (!$eose && microtime(true) - $lastMessage < self::IDLE_TIMEOUT) {
                    delay(0.25);
                }

                if (!$eose) {
                    $this->logger->debug('EssayistFeedService: idle timeout before EOSE', [
                        'received' => count($cards),
                    ]);
                }
            } finally {
                try {
                    if ($subscribed) {
                        $client->unsubscribe($relay, $subscriptionId);
                    }
                } finally {
                    $client->close();
                }
            }

            // Sort descending by createdAt (relay already sends desc, but enforce it)
            usort($cards, fn (object $a, object $b): int => $b->createdAt <=> $a->createdAt);

            return $cards;
        } catch (\Throwable $e) {
            $this->logger->warning('EssayistFeedService: failed to fetch from essayist relay', [
                'relay' => $relayUrl,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Convert a raw Nostr EVENT object into a stdClass card compatible with
     * the Card / CardList Twig components.
     */
    private function buildCard(Event $event): ?object
    {
        $eventData = $event->toArray();
        $pubkey    = $eventData['pubkey'] ?? null;
        $createdAt = $eventData['created_at'] ?? null;
        $tags      = is_array($eventData['tags'] ?? null) ? $eventData['tags'] : [];

        if (!$pubkey || !$createdAt) {
            return null;
        }

        $slug        = '';
        $title       = '';
        $summary     = '';
        $image       = '';
        $publishedAt = null;
        $topics      = [];

        foreach ($tags as $tag) {
            if (!is_array($tag) || !isset($tag[0])) {
                continue;
            }
            match ($tag[0]) {
                'd'            => $slug        = (string) ($tag[1] ?? ''),
                'title'        => $title       = (string) ($tag[1] ?? ''),
                'summary'      => $summary     = (string) ($tag[1] ?? ''),
                'image'        => $image       = (string) ($tag[1] ?? ''),
                'published_at' => $publishedAt = isset($tag[1]) ? (int) $tag[1] : null,
                't'            => $topics[]    = strtolower((string) ($tag[1] ?? '')),
                default        => null,
            };
        }

        if ($slug === '' || $title === '') {
            return null;
        }

        $npub = '';
        try {
            $npub = (static function (string $pubkey): string { return PublicKey::fromHex(strtolower(trim($pubkey)))?->toBech32() ?? throw new \InvalidArgumentException('Not a valid hex pubkey'); })((string) ((string) $pubkey));
        } catch (\Throwable) {
        }

        $naddr = '';
        try {
            $naddr = (string) Bech32::naddr(
                kind: 30023,
                pubkey: (string) $pubkey,
                identifier: $slug,
                relays: [],
            );
        } catch (\Throwable) {
        }

        $card              = new \stdClass();
        $card->pubkey      = (string) $pubkey;
        $card->npub        = $npub;
        $card->slug        = $slug;
        $card->title       = $title;
        $card->summary     = $summary !== '' ? $summary : null;
        $card->image       = $image !== '' ? $image : null;
        $card->kind        = 30023;
        $card->topics      = array_values(array_filter($topics));
        $card->createdAt   = (new \DateTimeImmutable())->setTimestamp((int) $createdAt);
        $card->publishedAt = $publishedAt !== null
            ? (new \DateTimeImmutable())->setTimestamp($publishedAt)
            : null;
        $card->naddr = $naddr;

        return $card;
    }
}
