<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Enum\KindBundles;
use App\Enum\KindsEnum;
use Psr\Log\LoggerInterface;

/**
 * Social interaction event operations: references, comments, zaps, highlights.
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
        private readonly ?UserRelayListService $userRelayListService = null,
    ) {}

    private const REFERENCE_FETCH_LIMIT = 500;

    // -------------------------------------------------------------------------
    // Combined Article Social Fetch (Phase 2)
    // -------------------------------------------------------------------------

    /**
     * Fetch all social interactions for an article coordinate in a single REQ.
     *
     * Combines reactions, comments, labels, zap requests, zap receipts, and
     * highlights — reducing 2 relay round-trips to 1.
     *
     * @param string   $coordinate "kind:pubkey:identifier"
     * @param int|null $since      Only events after this timestamp
     * @return array{reactions: object[], comments: object[], labels: object[], zap_requests: object[], zaps: object[], highlights: object[]}
     */
    public function fetchArticleSocial(string $coordinate, ?int $since = null): array
    {
        $this->logger->info('Fetching combined article social context', ['coordinate' => $coordinate]);

        $parts = explode(':', $coordinate, 3);
        if (count($parts) < 3) {
            throw new \InvalidArgumentException('Invalid coordinate format, expected kind:pubkey:identifier');
        }

        if ($this->nostrDefaultRelay) {
            $relayUrls = [$this->nostrDefaultRelay];
        } else {
            $pubkey = $parts[1];
            $relayUrls = $this->relaySetFactory->forAuthor($pubkey)->getRelays()
                ? array_map(fn($r) => $r->getUrl(), $this->relaySetFactory->forAuthor($pubkey)->getRelays())
                : [];
        }

        if (empty($relayUrls)) {
            $this->logger->warning('No relays available for article social fetch', ['coordinate' => $coordinate]);
            return KindBundles::categorizeArticleSocial([]);
        }

        $filter = ['kinds' => KindBundles::ARTICLE_SOCIAL, '#A' => [$coordinate]];
        if (is_int($since) && $since > 0) {
            $filter['since'] = $since;
        }
        $events = $this->executeFilters($relayUrls, [$filter], 30);

        $this->logger->info('Combined article social fetch complete', [
            'coordinate'  => $coordinate,
            'total_events' => count($events),
        ]);

        return KindBundles::categorizeArticleSocial($events);
    }

    // -------------------------------------------------------------------------
    // Comments
    // -------------------------------------------------------------------------

    /**
     * Get events that reference a parent event or coordinate.
     *
     * This intentionally does not filter by kind: Nostr clients use kind 1,
     * kind 1111, kind 7, repost kinds, zaps, and newer kinds to interact with
     * the same parent. Callers can classify the returned events after fetch.
     *
     * @param string      $ref          either "kind:pubkey:identifier" (addressable)
     *                                   or a 64-char lowercase hex event id
     *                                   (non-addressable parent)
     * @param int|null    $since        Only events after this timestamp
     * @param string|null $authorPubkey Author pubkey hint used to pick the
     *                                   right relay set when $ref is an event id
     * @return array   Deduplicated referencing events
     * @throws \InvalidArgumentException on malformed reference
     */
    public function getComments(string $ref, ?int $since = null, ?string $authorPubkey = null): array
    {
        $this->logger->info('Getting comments for parent ref', ['ref' => $ref]);

        $isCoordinate = str_contains($ref, ':');
        if ($isCoordinate) {
            $parts = explode(':', $ref, 3);
            if (count($parts) < 3) {
                throw new \InvalidArgumentException('Invalid coordinate format, expected kind:pubkey:identifier');
            }
            $pubkey = $parts[1];
        } else {
            if (!preg_match('/^[0-9a-f]{64}$/', $ref)) {
                throw new \InvalidArgumentException('Invalid parent reference, expected kind:pubkey:id or 64-hex event id');
            }
            $pubkey = $authorPubkey;
        }

        $relayUrls = [];
        if ($this->nostrDefaultRelay) {
            $relayUrls[] = $this->nostrDefaultRelay;
        }

        if ($pubkey) {
            try {
                if ($this->userRelayListService !== null) {
                    $authorRelays = $this->userRelayListService->getRelaysForAuthorContent($pubkey, 5);
                } else {
                    $relaySet = $this->relaySetFactory->forAuthor($pubkey);
                    $authorRelays = $relaySet->getRelays()
                        ? array_map(fn($r) => $r->getUrl(), $relaySet->getRelays())
                        : [];
                }

                foreach ($authorRelays as $relay) {
                    if (!in_array($relay, $relayUrls, true)) {
                        $relayUrls[] = $relay;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to get author relays for comment fetch', [
                    'ref' => $ref,
                    'pubkey' => $pubkey,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        foreach ($this->relayPool->getDefaultRelays() ?? [] as $relay) {
            $relayUrl = is_object($relay) ? $relay->getUrl() : (string) $relay;
            if ($relayUrl !== '' && !in_array($relayUrl, $relayUrls, true)) {
                $relayUrls[] = $relayUrl;
            }
        }

        $relayUrls = array_slice($relayUrls, 0, 6);

        $this->logger->info('Using relays for comments fetch', [
            'ref' => $ref,
            'relay_count' => count($relayUrls),
            'has_pubkey_hint' => $pubkey !== null,
        ]);

        if ($relayUrls === []) {
            return [];
        }

        $filters = [];
        $tagNames = $isCoordinate ? ['#A', '#a'] : ['#E', '#e'];

        foreach ($tagNames as $tagName) {
            $filter = [$tagName => [$ref], 'limit' => self::REFERENCE_FETCH_LIMIT];
            if (is_int($since) && $since > 0) {
                $filter['since'] = $since;
            }
            $filters[] = $filter;
        }

        return $this->executeFilters($relayUrls, $filters, 10);
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
    public function getHighlights(int $limit = 200): array
    {
        $this->logger->info('Fetching highlights from default relay');

        $relayUrls = $this->nostrDefaultRelay
            ? [$this->nostrDefaultRelay]
            : [($this->relayPool->getDefaultRelays()[0] ?? null)];
        $relayUrls = array_filter($relayUrls);

        return $this->executeFilters($relayUrls, [[
            'kinds' => [9802],
            'limit' => $limit,
            'since' => strtotime('-90 days'),
        ]], 30);
    }

    /**
     * Get highlights (kind 9802) for a specific article coordinate.
     * Fans out to the local relay, default relays, and the article author's relays for broader coverage.
     */
    public function getHighlightsForArticle(string $articleCoordinate, int $limit = 100): array
    {
        $this->logger->info('Fetching highlights for article', ['coordinate' => $articleCoordinate]);

        // Build relay list: local relay + default relays for broader coverage
        $relayUrls = [];
        if ($this->nostrDefaultRelay) {
            $relayUrls[] = $this->nostrDefaultRelay;
        }
        $defaultRelays = $this->relayPool->getDefaultRelays();
        foreach ($defaultRelays as $relay) {
            $relay = is_object($relay) ? $relay->getUrl() : (string) $relay;
            if (!in_array($relay, $relayUrls, true)) {
                $relayUrls[] = $relay;
            }
        }

        // Fan out to the article author's relays (extracted from the coordinate: kind:pubkey:slug)
        $parts = explode(':', $articleCoordinate, 3);
        if (count($parts) === 3 && $this->userRelayListService !== null) {
            $authorPubkey = $parts[1];
            try {
                $authorRelays = $this->userRelayListService->getRelaysForAuthorContent($authorPubkey, 5);
                foreach ($authorRelays as $relay) {
                    if (!in_array($relay, $relayUrls, true)) {
                        $relayUrls[] = $relay;
                    }
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to get author relays for highlight fanout', [
                    'pubkey' => $authorPubkey,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Cap at 6 relays to keep fetching reasonably fast
        $relayUrls = array_slice($relayUrls, 0, 6);

        if (empty($relayUrls)) {
            $this->logger->warning('No relays available for highlights fetch', ['coordinate' => $articleCoordinate]);
            return [];
        }

        $this->logger->info('Fetching highlights from relays', [
            'coordinate' => $articleCoordinate,
            'relays' => $relayUrls,
        ]);

        return $this->executeFilters($relayUrls, [[
            'kinds' => [9802],
            'limit' => $limit,
            '#a' => [$articleCoordinate],
        ]], 30);
    }

    /**
     * @param list<string> $relayUrls
     * @param list<array<string, mixed>> $filters
     * @return list<object>
     */
    private function executeFilters(array $relayUrls, array $filters, int $timeout): array
    {
        $request = new RelayQueryRequest(
            $this->relaySetFactory->fromUrls($relayUrls),
            array_map(
                static fn (array $filter): array
                    => NostrRequestExecutor::normaliseFilterArray($filter),
                $filters,
            ),
        );
        $request->setTimeout($timeout);

        $events = $this->executor->process(
            $this->executor->execute($request),
            static fn (object $event): object => $event,
        );

        $unique = [];
        foreach ($events as $event) {
            $id = isset($event->id) ? (string) $event->id : spl_object_hash($event);
            $unique[$id] = $event;
        }

        return array_values($unique);
    }
}
