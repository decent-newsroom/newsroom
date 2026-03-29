<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Util\NostrPhp\TweakedRequest;
use Psr\Log\LoggerInterface;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\RelaySet;
use swentel\nostr\Subscription\Subscription;

/**
 * Low-level Nostr relay request machinery.
 *
 * Extracted from NostrClient to eliminate the ~15× copy-paste of:
 *   processResponse($this->executeRequest($request), fn($e) => $e)
 *
 * Also provides:
 *   - fetchFirst()        — "try primary relays → fallback" pattern (was 4× inline)
 *   - fetchByTimeRange()  — collapses getMediaEventsByTimeRange + getCurationEventsByTimeRange
 */
class NostrRequestExecutor
{
    public function __construct(
        private readonly NostrRelayPool  $relayPool,
        private readonly RelaySetFactory $relaySetFactory,
        private readonly LoggerInterface $logger,
        private readonly ?string         $nostrDefaultRelay = null,
    ) {}

    /**
     * Build a TweakedRequest from kinds + filter array + optional RelaySet.
     */
    public function buildRequest(
        array      $kinds,
        array      $filters = [],
        ?RelaySet  $relaySet = null,
        mixed      $stopGap = null,
    ): TweakedRequest {
        $subscription = new Subscription();
        $filter = new Filter();

        if (!empty($kinds)) {
            $filter->setKinds($kinds);
        }

        foreach ($filters as $key => $value) {
            $method = 'set' . ucfirst($key);
            if (method_exists($filter, $method)) {
                if ($key === 'tag') {
                    $filter->setTag($value[0], $value[1]);
                } else {
                    $filter->$method($value);
                }
            }
        }

        $this->logger->debug('Relay set for request', [
            'relays' => $relaySet ? $relaySet->getRelays() : 'default',
        ]);

        $requestMessage = new RequestMessage($subscription->getId(), [$filter]);

        return (new TweakedRequest(
            $relaySet ?? $this->relaySetFactory->getDefault(),
            $requestMessage,
            $this->logger
        ))->stopOnEventId($stopGap);
    }

    /**
     * Execute a relay request, routing through the gateway when enabled.
     *
     * This is the single interception point for all relay reads.
     *
     * @return array<string, array> relay URL => responses
     */
    public function execute(TweakedRequest $request, ?string $pubkey = null, int $gatewayTimeout = 8): array
    {
        if (!$this->relayPool->isGatewayEnabled()) {
            return $request->send();
        }

        $gatewayClient = $this->relayPool->getGatewayClient();
        if (!$gatewayClient) {
            return $request->send();
        }

        $relayUrls = $request->getRelayUrls();
        $payload   = $request->getPayload();

        $decoded = json_decode($payload, true);
        $filter  = (is_array($decoded) && ($decoded[0] ?? '') === 'REQ' && isset($decoded[2]))
            ? $decoded[2]
            : [];

        $localRelay   = $this->nostrDefaultRelay ?: null;
        // PROJECT is the public wss:// alias for the same physical relay as LOCAL.
        // Treat it as local so it is never routed through the gateway.
        $projectRelay = $this->relayPool->getRelayRegistry()->getProjectRelay();

        $localUrls    = [];
        $externalUrls = [];

        foreach ($relayUrls as $url) {
            $normalized = $this->relayPool->normalizeRelayUrl($url);
            $isLocal = ($localRelay   && $normalized === $this->relayPool->normalizeRelayUrl($localRelay))
                    || ($projectRelay && $normalized === $this->relayPool->normalizeRelayUrl($projectRelay));
            if ($isLocal) {
                $localUrls[] = $url;
            } else {
                $externalUrls[] = $url;
            }
        }

        $results = [];

        // Local relay: always direct (fast, no AUTH overhead)
        if (!empty($localUrls)) {
            $localRelaySet = new RelaySet();
            foreach ($localUrls as $url) {
                $localRelaySet->addRelay($this->relayPool->getRelay($url));
            }
            $msg          = new RequestMessage((new Subscription())->getId(), [self::buildFilterFromArray($filter)]);
            $localRequest = new TweakedRequest($localRelaySet, $msg, $this->logger);
            $results      += $localRequest->send();
        }

        // External relays: route through gateway.
        // The caller can configure the timeout; default (8s) keeps headroom
        // for the messenger worker's 15s execution limit.  Targeted hint-relay
        // queries pass a higher value so slow relays have time to EOSE.
        if (!empty($externalUrls)) {
            $gatewayResult = $gatewayClient->query($externalUrls, $filter, $pubkey, $gatewayTimeout);

            if (!empty($gatewayResult['errors'])) {
                $this->logger->warning('Gateway returned errors for external relays', [
                    'errors' => $gatewayResult['errors'],
                    'pubkey' => $pubkey ? substr($pubkey, 0, 8) . '...' : null,
                ]);
            }

            if (empty($gatewayResult['events'])) {
                // Gateway returned nothing — log it but do NOT fall back to
                // direct WebSocket connections. Direct connections to external
                // relays involve TLS handshakes + NIP-42 AUTH (ECC crypto) that
                // easily exceed the messenger worker's 15s execution limit and
                // cause a crash loop. The data will be retried on the next
                // scheduled attempt or served from cache.
                $this->logger->info('Gateway returned no events for external relays', [
                    'relays' => $externalUrls,
                    'errors' => $gatewayResult['errors'] ?? [],
                ]);
            } else {
                $responses       = [];
                $syntheticSubId  = 'gw-' . substr(uniqid(), -8);
                foreach ($gatewayResult['events'] as $eventData) {
                    $eventObj    = is_object($eventData) ? $eventData : json_decode(json_encode($eventData));
                    $wireFormat  = ['EVENT', $syntheticSubId, $eventObj];
                    $responses[] = new \swentel\nostr\RelayResponse\RelayResponseEvent($wireFormat);
                }
                if (!empty($responses)) {
                    $results[$externalUrls[0]] = $responses;
                }
            }
        }

        return $results;
    }

    /**
     * Process relay responses, calling $handler for each EVENT item.
     *
     * The handler receives the raw event object and may return a value (which
     * is collected into the result array) or null (which is ignored).
     *
     * @return array  Collected non-null handler return values
     */
    public function process(array $response, callable $handler): array
    {
        $results = [];

        foreach ($response as $relayUrl => $relayRes) {
            if ($relayRes instanceof \Exception) {
                $this->logger->error('Relay error', [
                    'relay' => $relayUrl,
                    'error' => $relayRes->getMessage(),
                ]);
                continue;
            }

            $this->logger->debug('Processing relay response', [
                'relay'    => $relayUrl,
                'response' => $relayRes,
            ]);

            foreach ($relayRes as $item) {
                try {
                    if (!is_object($item)) {
                        $this->logger->debug('Invalid response item from ' . $relayUrl, [
                            'relay' => $relayUrl,
                            'item'  => $item,
                        ]);
                        continue;
                    }

                    switch ($item->type) {
                        case 'EVENT':
                            $this->logger->debug('Processing event', [
                                'relay'    => $relayUrl,
                                'event_id' => $item->event->id ?? 'unknown',
                            ]);
                            $result = $handler($item->event);
                            if ($result !== null) {
                                $results[] = $result;
                            }
                            break;

                        case 'AUTH':
                            $this->logger->debug('Relay required authentication (handled during request)', [
                                'relay'    => $relayUrl,
                                'response' => $item,
                            ]);
                            break;

                        case 'ERROR':
                        case 'NOTICE':
                            $this->logger->debug('Relay error/notice', [
                                'relay'   => $relayUrl,
                                'type'    => $item->type,
                                'message' => $item->message ?? 'No message',
                            ]);
                            break;
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Error processing event from relay', [
                        'relay' => $relayUrl,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $results;
    }

    /**
     * Convenience: build + execute + process in one call.
     *
     * Replaces the ~15× inline:
     *   processResponse($this->executeRequest($request), fn($e) => $e)
     */
    public function fetch(
        array      $kinds,
        array      $filters = [],
        ?RelaySet  $relaySet = null,
        ?callable  $handler = null,
        ?string    $pubkey = null,
        int        $gatewayTimeout = 8,
    ): array {
        $request = $this->buildRequest($kinds, $filters, $relaySet);
        return $this->process($this->execute($request, $pubkey, $gatewayTimeout), $handler ?? fn($e) => $e);
    }

    /**
     * Try $primary relay set first; if no events are returned, fall back to $fallback.
     *
     * Replaces the 4× "try author relays → fallback to content relays" pattern.
     */
    public function fetchFirst(
        array     $kinds,
        array     $filters,
        RelaySet  $primary,
        ?RelaySet $fallback = null,
        ?string   $pubkey = null,
        int       $gatewayTimeout = 8,
    ): ?object {
        $events = $this->fetch($kinds, $filters, $primary, null, $pubkey, $gatewayTimeout);
        if (!empty($events)) {
            return $events[0];
        }

        if ($fallback !== null) {
            $events = $this->fetch($kinds, $filters, $fallback, null, $pubkey, $gatewayTimeout);
            return !empty($events) ? $events[0] : null;
        }

        return null;
    }

    /**
     * Fetch events within a time window.
     *
     * Collapses getMediaEventsByTimeRange() + getCurationEventsByTimeRange() into
     * a single parametrized method.
     *
     * @param array    $kinds       Event kinds to fetch
     * @param int      $from        Unix timestamp (0 = auto: now - $defaultDays days)
     * @param int      $to          Unix timestamp (0 = now)
     * @param int      $limit       Maximum number of events
     * @param int      $defaultDays Days to look back when $from = 0
     * @param RelaySet|null $relaySet Relay set to query (null = first 2 content relays)
     * @param array    $extraFilters Additional filter params (e.g. ['authors' => [...]])
     */
    public function fetchByTimeRange(
        array      $kinds,
        int        $from = 0,
        int        $to = 0,
        int        $limit = 500,
        int        $defaultDays = 7,
        ?RelaySet  $relaySet = null,
        array      $extraFilters = [],
    ): array {
        if ($to === 0) {
            $to = time();
        }
        if ($from === 0) {
            $from = $to - ($defaultDays * 24 * 60 * 60);
        }

        $this->logger->info('Fetching events by time range', [
            'kinds' => $kinds,
            'from'  => date('Y-m-d H:i:s', $from),
            'to'    => date('Y-m-d H:i:s', $to),
            'limit' => $limit,
        ]);

        $filters = array_merge($extraFilters, [
            'since' => $from,
            'until' => $to,
            'limit' => $limit,
        ]);

        return $this->fetch($kinds, $filters, $relaySet);
    }

    /**
     * Build a Filter object from a plain associative array (for gateway re-routing).
     */
    public static function buildFilterFromArray(array $filter): Filter
    {
        $f = new Filter();
        if (isset($filter['kinds']))   { $f->setKinds($filter['kinds']); }
        if (isset($filter['authors'])) { $f->setAuthors($filter['authors']); }
        if (isset($filter['ids']))     { $f->setIds($filter['ids']); }
        if (isset($filter['limit']))   { $f->setLimit($filter['limit']); }
        if (isset($filter['since']))   { $f->setSince($filter['since']); }
        if (isset($filter['until']))   { $f->setUntil($filter['until']); }
        // NIP-01 tag filters: #e, #p, #t, #d, #a, etc.
        foreach ($filter as $key => $value) {
            if (str_starts_with($key, '#') && strlen($key) === 2 && is_array($value)) {
                $f->setTag($key, $value);
            }
        }
        return $f;
    }

    // =========================================================================
    // Publishing
    // =========================================================================

    /**
     * Publish a signed event to a list of relay URLs.
     *
     * Delegates to the relay pool's direct-publish path (each relay is contacted
     * independently, fresh connections, no gateway).
     *
     * @return array Results keyed by relay URL
     */
    public function publish(\swentel\nostr\Event\Event $event, array $relayUrls, ?string $pubkey = null, int $timeout = 30): array
    {
        return $this->relayPool->publish($event, $relayUrls, $pubkey, $timeout);
    }
}

