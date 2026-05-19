<?php

declare(strict_types=1);

namespace App\Service\Essayist;

use App\Util\NostrKeyUtil;
use App\Util\NostrPhp\RelaySubscriptionHandler;
use nostriphant\NIP19\Bech32;
use Psr\Log\LoggerInterface;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Subscription\Subscription;
use WebSocket\Message\Text;

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
    ) {
    }

    /**
     * Fetch the latest kind:30023 articles from strfry-essayist.
     *
     * @return object[] Array of stdClass cards, sorted by createdAt descending.
     *                  Each card has: pubkey, slug, title, summary, image, kind,
     *                  topics, createdAt (\DateTimeImmutable), publishedAt (\DateTimeImmutable|null)
     */
    public function fetchLatest(int $limit = 50): array
    {
        $filter = new Filter();
        $filter->setKinds([30023]);
        $filter->setLimit($limit);

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

        $filter = new Filter();
        $filter->setKinds([30023]);
        $filter->setLimit($limit);

        try {
            $filter->setAuthors($pubkeys);
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

        $filter = new Filter();
        $filter->setKinds([30023]);
        $filter->setLimit($limit);

        try {
            $filter->setTags(['#t' => $hashtags]);
        } catch (\Throwable $e) {
            $this->logger->warning('EssayistFeedService: invalid hashtags for tag filter', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        return $this->doFetch($filter);
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

        try {
            $relay = new Relay($this->internalRelayUrl);
            $relay->connect();

            $client = $relay->getClient();
            $client->setTimeout(self::IDLE_TIMEOUT);

            $subscription = new Subscription();
            $subId        = $subscription->setId();


            $reqMsg = new RequestMessage($subId, [$filter]);
            $client->text($reqMsg->generate());

            $handler     = new RelaySubscriptionHandler($this->logger);
            $cards       = [];
            $lastMessage = time();
            $eose        = false;

            while (!$eose) {
                try {
                    $resp = $client->receive();
                } catch (\Throwable $e) {
                    if ($handler->isTimeoutError($e)) {
                        if (time() - $lastMessage >= self::IDLE_TIMEOUT) {
                            $this->logger->debug('EssayistFeedService: idle timeout before EOSE', [
                                'received' => count($cards),
                            ]);
                            break;
                        }
                        continue;
                    }
                    if ($handler->isBadMessageError($e)) {
                        continue;
                    }
                    throw $e;
                }

                if (!$resp instanceof Text) {
                    continue;
                }

                $lastMessage = time();
                $decoded     = json_decode($resp->getContent());

                if (!is_array($decoded) || !isset($decoded[0])) {
                    continue;
                }

                switch ($decoded[0]) {
                    case 'EVENT':
                        $event = $decoded[2] ?? null;
                        if (!is_object($event)) {
                            break;
                        }
                        $card = $this->buildCard($event);
                        if ($card !== null) {
                            $cards[] = $card;
                        }
                        break;

                    case 'EOSE':
                        $eose = true;
                        $this->logger->debug('EssayistFeedService: EOSE received', [
                            'received' => count($cards),
                        ]);
                        break;

                    case 'AUTH':
                        // strfry-essayist is internal-only; no NIP-42 challenge expected here
                        // but handle gracefully if gateway is in the path
                        $challenge = $decoded[1] ?? null;
                        if ($challenge) {
                            $handler->handleAuth($relay, $client, (string) $challenge);
                            // Re-send REQ after AUTH
                            $client->text($reqMsg->generate());
                        }
                        break;

                    case 'CLOSED':
                        $this->logger->warning('EssayistFeedService: relay closed subscription', [
                            'message' => $decoded[2] ?? $decoded[1] ?? '',
                        ]);
                        $eose = true;
                        break;

                    default:
                        break;
                }
            }

            $handler->sendClose($client, $subId);

            try {
                $client->close();
            } catch (\Throwable) {
                // ignore close errors
            }

            // Sort descending by createdAt (relay already sends desc, but enforce it)
            usort($cards, fn (object $a, object $b): int => $b->createdAt <=> $a->createdAt);

            return $cards;
        } catch (\Throwable $e) {
            $this->logger->warning('EssayistFeedService: failed to fetch from essayist relay', [
                'relay' => $this->internalRelayUrl,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Convert a raw Nostr EVENT object into a stdClass card compatible with
     * the Card / CardList Twig components.
     */
    private function buildCard(object $event): ?object
    {
        $pubkey    = $event->pubkey    ?? null;
        $createdAt = $event->created_at ?? null;
        $tags      = is_array($event->tags ?? null) ? $event->tags : [];

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
            $npub = NostrKeyUtil::hexToNpub((string) $pubkey);
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

