<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Enum\KindsEnum;
use Psr\Log\LoggerInterface;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Subscription\Subscription;

/**
 * Social interaction event operations: comments, zaps, highlights.
 *
 * Extracted from NostrClient.
 */
class SocialEventService
{
    public function __construct(
        private readonly NostrRequestExecutor $executor,
        private readonly RelaySetFactory      $relaySetFactory,
        private readonly NostrRelayPool       $relayPool,
        private readonly LoggerInterface      $logger,
        private readonly ?string              $nostrDefaultRelay = null,
    ) {}

    // -------------------------------------------------------------------------
    // Comments
    // -------------------------------------------------------------------------

    /**
     * Get comments (kind 1111) and zap receipts (kind 9735) for a coordinate.
     *
     * @param string   $coordinate  "kind:pubkey:identifier"
     * @param int|null $since       Only events after this timestamp
     * @return array   Deduplicated comment/zap events
     * @throws \InvalidArgumentException on malformed coordinate
     */
    public function getComments(string $coordinate, ?int $since = null): array
    {
        $this->logger->info('Getting comments for coordinate', ['coordinate' => $coordinate]);

        $parts = explode(':', $coordinate, 3);
        if (count($parts) < 3) {
            throw new \InvalidArgumentException('Invalid coordinate format, expected kind:pubkey:identifier');
        }
        $pubkey = $parts[1];

        if ($this->nostrDefaultRelay) {
            $authorRelays = [$this->nostrDefaultRelay];
            $this->logger->info('Using local relay for comments fetch', ['relay' => $this->nostrDefaultRelay]);
        } else {
            $authorRelays = $this->relaySetFactory->forAuthor($pubkey)->getRelays()
                ? array_map(fn($r) => $r->getUrl(), $this->relaySetFactory->forAuthor($pubkey)->getRelays())
                : [];
            $this->logger->info('Using author relays for comments fetch', [
                'coordinate'  => $coordinate,
                'relay_count' => count($authorRelays),
            ]);
        }

        $subscription   = new Subscription();
        $subscriptionId = $subscription->setId();
        $filter         = new Filter();
        $filter->setKinds([KindsEnum::COMMENTS->value, KindsEnum::ZAP_RECEIPT->value]);
        $filter->setTag('#A', [$coordinate]);

        if (is_int($since) && $since > 0) {
            $filter->setSince($since);
        }

        $requestMessage = new RequestMessage($subscriptionId, [$filter]);
        $responses      = $this->relayPool->sendToRelays(
            $authorRelays,
            fn() => $requestMessage,
            10,
            $subscriptionId
        );

        $uniqueEvents = [];
        $this->executor->process($responses, function ($event) use (&$uniqueEvents) {
            $this->logger->debug('Received comment event', ['event_id' => $event->id]);
            $uniqueEvents[$event->id] = $event;
            return null;
        });

        return array_values($uniqueEvents);
    }

    // -------------------------------------------------------------------------
    // Zaps
    // -------------------------------------------------------------------------

    /**
     * Get zap receipts (kind 9735) for a specific coordinate.
     *
     * @param string $coordinate  "kind:pubkey:identifier"
     */
    public function getZaps(string $coordinate): array
    {
        $this->logger->info('Getting zaps for coordinate', ['coordinate' => $coordinate]);

        $parts    = explode(':', $coordinate, 3);
        $pubkey   = $parts[1];
        $relaySet = $this->relaySetFactory->forAuthor($pubkey);

        return $this->executor->fetch(
            kinds: [KindsEnum::ZAP_RECEIPT->value],
            filters: ['tag' => ['#a', [$coordinate]]],
            relaySet: $relaySet,
            handler: function ($event) {
                $this->logger->debug('Received zap event', ['event_id' => $event->id]);
                return $event;
            }
        );
    }

    // -------------------------------------------------------------------------
    // Highlights
    // -------------------------------------------------------------------------

    /**
     * Get all highlights (NIP-84, kind 9802) from the local/default relay.
     */
    public function getHighlights(int $limit = 50): array
    {
        $this->logger->info('Fetching highlights from default relay');

        $subscription   = new Subscription();
        $subscriptionId = $subscription->setId();
        $filter         = new Filter();
        $filter->setKinds([9802]);
        $filter->setLimit($limit);
        $filter->setSince(strtotime('-30 days'));

        $requestMessage = new RequestMessage($subscriptionId, [$filter]);

        $relayUrls = $this->nostrDefaultRelay
            ? [$this->nostrDefaultRelay]
            : [($this->relayPool->getDefaultRelays()[0] ?? null)];
        $relayUrls = array_filter($relayUrls);

        $responses = $this->relayPool->sendToRelays(
            $relayUrls,
            fn() => $requestMessage,
            30,
            $subscriptionId
        );

        $uniqueEvents = [];
        $this->executor->process($responses, function ($event) use (&$uniqueEvents) {
            $this->logger->debug('Received highlight event', ['event_id' => $event->id]);
            $uniqueEvents[$event->id] = $event;
            return null;
        });

        return array_values($uniqueEvents);
    }

    /**
     * Get highlights (kind 9802) for a specific article coordinate.
     */
    public function getHighlightsForArticle(string $articleCoordinate, int $limit = 100): array
    {
        $this->logger->info('Fetching highlights for article', ['coordinate' => $articleCoordinate]);

        $subscription   = new Subscription();
        $subscriptionId = $subscription->setId();
        $filter         = new Filter();
        $filter->setKinds([9802]);
        $filter->setLimit($limit);
        $filter->setTags(['#a' => [$articleCoordinate]]);

        $requestMessage = new RequestMessage($subscriptionId, [$filter]);

        if ($this->nostrDefaultRelay) {
            $relayUrls = [$this->nostrDefaultRelay];
            $this->logger->info('Using local relay for highlights fetch', [
                'relay'      => $this->nostrDefaultRelay,
                'coordinate' => $articleCoordinate,
            ]);
        } else {
            $defaultRelays = $this->relayPool->getDefaultRelays();
            $relayUrls     = empty($defaultRelays) ? [] : [$defaultRelays[0]];
            $this->logger->info('Using fallback relay for highlights fetch', [
                'coordinate' => $articleCoordinate,
                'relay'      => $relayUrls[0] ?? 'none',
            ]);
        }

        if (empty($relayUrls)) {
            $this->logger->warning('No relays available for highlights fetch', ['coordinate' => $articleCoordinate]);
            return [];
        }

        $responses = $this->relayPool->sendToRelays(
            $relayUrls,
            fn() => $requestMessage,
            30,
            $subscriptionId
        );

        $uniqueEvents = [];
        $this->executor->process($responses, function ($event) use (&$uniqueEvents) {
            $this->logger->debug('Received highlight event for article', ['event_id' => $event->id]);
            $uniqueEvents[$event->id] = $event;
            return null;
        });

        return array_values($uniqueEvents);
    }
}

