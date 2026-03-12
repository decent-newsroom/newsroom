<?php

namespace App\Service\Nostr;

use App\Entity\Article;
use App\Enum\KindsEnum;
use nostriphant\NIP19\Data;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event;

/**
 * Facade over the focused Nostr service classes.
 *
 * All domain-specific work is delegated to:
 *   - RelaySetFactory        relay set construction
 *   - NostrRequestExecutor   low-level request / response machinery
 *   - ArticleFetchService    long-form content
 *   - MediaEventService      pictures & videos (kinds 20/21/22)
 *   - SocialEventService     comments, zaps, highlights
 *   - UserProfileService     metadata, relay lists, interests, follows, bookmarks
 *
 * Public methods are kept for backward compatibility with existing callers.
 */
class NostrClient
{
    public function __construct(
        private readonly LoggerInterface        $logger,
        private readonly NostrRelayPool         $relayPool,
        private readonly RelayRegistry          $relayRegistry,
        private readonly UserRelayListService   $userRelayListService,
        private readonly RelaySetFactory        $relaySetFactory,
        private readonly NostrRequestExecutor   $executor,
        private readonly ArticleFetchService    $articleFetchService,
        private readonly MediaEventService      $mediaEventService,
        private readonly SocialEventService     $socialEventService,
        private readonly UserProfileService     $userProfileService,
        private readonly ?string                $nostrDefaultRelay = null,
    ) {}

    // =========================================================================
    // Publishing
    // =========================================================================

    public function publishEvent(Event $event, array $relays, int $timeout = 30): array
    {
        if (empty($relays)) {
            $relays = $this->userRelayListService->getTopRelaysForAuthor($event->getPublicKey());
        } else {
            $relays = $this->relayPool->ensureLocalRelayInList($relays);
        }

        $this->logger->info('Publishing event to relays', [
            'event_id'       => $event->getId(),
            'relay_count'    => count($relays),
            'relays'         => $relays,
            'timeout'        => $timeout,
        ]);

        try {
            $startTime = microtime(true);

            // Publish directly to each relay independently. One relay failing
            // does not affect the others.
            $results  = $this->relayPool->publish($event, $relays, $event->getPublicKey(), $timeout);
            $duration = microtime(true) - $startTime;

            $this->logger->info('Completed relay publish', [
                'event_id'     => $event->getId(),
                'duration'     => round($duration, 2),
                'result_count' => count($results),
            ]);

            return $results;

        } catch (\Exception $e) {
            $this->logger->error('Error publishing event to relays', [
                'event_id' => $event->getId(),
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
            return [];
        }
    }

    // =========================================================================
    // Generic event fetching
    // =========================================================================

    /**
     * @throws \Exception
     */
    public function getEventById(string $eventId, array $relays = []): ?object
    {
        $this->logger->info('Getting event by ID', ['event_id' => $eventId, 'relays' => $relays]);

        $relays    = $this->relayPool->ensureLocalRelayInList($relays);
        $allRelays = array_values(array_unique(array_merge($relays, $this->relayRegistry->getContentRelays())));
        $allRelays = array_slice($allRelays, 0, 3);

        foreach ($allRelays as $relay) {
            $this->logger->debug('Trying relay for event', ['relay' => $relay, 'event_id' => $eventId]);
            try {
                $request = $this->executor->buildRequest(
                    kinds: [],
                    filters: ['ids' => [$eventId], 'limit' => 1],
                    relaySet: $this->relaySetFactory->fromUrls([$relay]),
                    stopGap: $eventId
                );
                $events = $this->executor->process(
                    $this->executor->execute($request),
                    fn($event) => $event
                );
                if (!empty($events)) {
                    return $events[0];
                }
            } catch (\Throwable $e) {
                $this->logger->debug('Relay failed for event lookup', [
                    'relay'    => $relay,
                    'event_id' => $eventId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * @throws \Exception
     */
    public function getEventsByIds(array $eventIds, array $relays = []): array
    {
        if (empty($eventIds)) {
            return [];
        }

        $this->logger->info('Getting events by IDs', ['event_ids' => $eventIds, 'relays' => $relays]);

        if (!empty($relays)) {
            $relays = $this->relayPool->ensureLocalRelayInList($relays);
        }

        $relaySet = empty($relays)
            ? $this->relaySetFactory->getDefault()
            : $this->relaySetFactory->fromUrls($relays);

        $events = $this->executor->fetch(
            kinds: [],
            filters: ['ids' => $eventIds],
            relaySet: $relaySet,
            handler: fn($event) => $event,
        );

        $eventsMap = [];
        foreach ($events as $event) {
            $eventsMap[$event->id] = $event;
        }
        return $eventsMap;
    }

    /**
     * @throws \Exception
     */
    public function getEventByNaddr(array $decoded): ?object
    {
        $this->logger->info('Getting event by naddr', ['decoded' => $decoded]);

        $kind       = $decoded['kind']       ?? 30023;
        $pubkey     = $decoded['pubkey']     ?? '';
        $identifier = $decoded['identifier'] ?? '';
        $relays     = $decoded['relays']     ?? [];

        if (empty($pubkey) || empty($identifier)) {
            return null;
        }

        $primary  = $this->relaySetFactory->forAuthorWithFallback($pubkey, $relays);
        $fallback = $this->relaySetFactory->getDefault();

        return $this->executor->fetchFirst(
            kinds: [$kind],
            filters: ['authors' => [$pubkey], 'tag' => ['#d', [$identifier]]],
            primary: $primary,
            fallback: $fallback,
        );
    }

    /**
     * Fetch multiple events by coordinate strings ("kind:pubkey:identifier") in batch.
     *
     * @param string[] $coordinates
     * @return array<string, object>  keyed by coordinate string
     */
    public function getEventsByCoordinates(array $coordinates): array
    {
        if (empty($coordinates)) {
            return [];
        }

        $groups = [];
        foreach ($coordinates as $coord) {
            $parts = explode(':', $coord, 3);
            if (count($parts) !== 3) {
                continue;
            }
            [$kind, $pubkey, $identifier] = $parts;
            $groupKey                           = $kind . ':' . $pubkey;
            $groups[$groupKey]['kind']          = (int) $kind;
            $groups[$groupKey]['pubkey']        = $pubkey;
            $groups[$groupKey]['identifiers'][] = $identifier;
        }

        $results = [];

        foreach ($groups as $group) {
            $kind        = $group['kind'];
            $pubkey      = $group['pubkey'];
            $identifiers = array_unique($group['identifiers']);

            $this->logger->info('Batch fetching events by coordinate', [
                'kind'   => $kind,
                'pubkey' => substr($pubkey, 0, 8) . '...',
                'count'  => count($identifiers),
            ]);

            $relayUrls = $this->nostrDefaultRelay
                ? [$this->nostrDefaultRelay]
                : $this->userRelayListService->getTopRelaysForAuthor($pubkey);

            $filters = ['authors' => [$pubkey], 'tag' => ['#d', $identifiers]];
            $events  = $this->executor->fetch([$kind], $filters, $this->relaySetFactory->fromUrls($relayUrls));

            if (empty($events) && $this->nostrDefaultRelay) {
                $fallbackRelays = $this->userRelayListService->getTopRelaysForAuthor($pubkey);
                if (!empty($fallbackRelays)) {
                    $events = $this->executor->fetch([$kind], $filters, $this->relaySetFactory->fromUrls($fallbackRelays));
                }
            }

            foreach ($events as $event) {
                $dTag = null;
                foreach ($event->tags ?? [] as $tag) {
                    if (is_array($tag) && ($tag[0] ?? '') === 'd' && isset($tag[1])) {
                        $dTag = $tag[1];
                        break;
                    }
                }
                if ($dTag === null) {
                    continue;
                }
                $coordKey = $kind . ':' . $pubkey . ':' . $dTag;
                if (!isset($results[$coordKey]) ||
                    ($event->created_at ?? 0) > ($results[$coordKey]->created_at ?? 0)) {
                    $results[$coordKey] = $event;
                }
            }
        }

        return $results;
    }

    /**
     * @throws \Exception
     */
    public function getEventFromDescriptor(mixed $descriptor): ?\stdClass
    {
        if (!is_object($descriptor) || !isset($descriptor->type, $descriptor->decoded)) {
            $this->logger->error('Invalid descriptor format', ['descriptor' => $descriptor]);
            return null;
        }

        /** @var Data $data */
        $data = json_decode($descriptor->decoded);

        $request = isset($data->id)
            ? $this->executor->buildRequest(
                kinds: [$data->kind],
                filters: ['e' => [$data->id]],
                relaySet: $this->relaySetFactory->getDefault()
            )
            : $this->executor->buildRequest(
                kinds: [$data->kind],
                filters: ['authors' => [$data->pubkey], 'd' => [$data->identifier]],
                relaySet: $this->relaySetFactory->getDefault()
            );

        $events = $this->executor->process(
            $this->executor->execute($request),
            function ($received) {
                $this->logger->info('Getting event', ['item' => $received]);
                return $received;
            }
        );

        if (!empty($events)) {
            return $events[0];
        }

        $this->logger->warning('No events found for descriptor', ['descriptor' => $descriptor]);
        return null;
    }

    // =========================================================================
    // Article / long-form — delegates to ArticleFetchService
    // =========================================================================

    /** @see ArticleFetchService::ingestRange() */
    public function getLongFormContent(?int $from = null, ?int $to = null): void
    {
        $this->articleFetchService->ingestRange($from, $to);
    }

    /** @see ArticleFetchService::fetchFromNaddr() */
    public function getLongFormFromNaddr(string $slug, array $relayList, string $author, int $kind): bool
    {
        return $this->articleFetchService->fetchFromNaddr($slug, $relayList, $author, $kind);
    }

    /** @see ArticleFetchService::fetchForPubkey() */
    public function getLongFormContentForPubkey(string $ident, ?int $since = null, ?int $kind = KindsEnum::LONGFORM->value): array
    {
        return $this->articleFetchService->fetchForPubkey($ident, $since, $kind);
    }

    /** @see ArticleFetchService::fetchLatest() */
    public function getLatestLongFormArticles(int $limit = 50, ?int $since = null): array
    {
        return $this->articleFetchService->fetchLatest($limit, $since);
    }

    /** @see ArticleFetchService::fetchBySlugList() */
    public function getArticles(array $slugs): array
    {
        return $this->articleFetchService->fetchBySlugList($slugs);
    }

    /** @see ArticleFetchService::fetchByCoordinates() */
    public function getArticlesByCoordinates(array $coordinates): array
    {
        return $this->articleFetchService->fetchByCoordinates($coordinates);
    }

    /** @see ArticleFetchService::save() */
    public function saveEachArticleToTheDatabase(Article $article): void
    {
        $this->articleFetchService->save($article);
    }

    // =========================================================================
    // Media events — delegates to MediaEventService
    // =========================================================================

    /** @see MediaEventService::fetchForPubkey() */
    public function getPictureEventsForPubkey(string $ident, int $limit = 20): array
    {
        return $this->mediaEventService->fetchForPubkey($ident, [20], $limit);
    }

    /** @see MediaEventService::fetchForPubkey() */
    public function getNormalVideosForPubkey(string $ident, int $limit = 20): array
    {
        return $this->mediaEventService->fetchForPubkey($ident, [21], $limit);
    }

    /** @see MediaEventService::fetchForPubkey() */
    public function getVideoShortsForPubkey(string $ident, int $limit = 20): array
    {
        return $this->mediaEventService->fetchForPubkey($ident, [22], $limit);
    }

    /** @see MediaEventService::fetchForPubkey() */
    public function getAllMediaEventsForPubkey(string $ident, int $limit = 30): array
    {
        return $this->mediaEventService->fetchForPubkey($ident, [20, 21, 22], $limit);
    }

    /** @see MediaEventService::fetchRecent() */
    public function getRecentMediaEvents(int $limit = 100): array
    {
        return $this->mediaEventService->fetchRecent($limit);
    }

    /** @see MediaEventService::fetchByHashtags() */
    public function getMediaEventsByHashtags(array $hashtags, array $kinds = [20, 21, 22]): array
    {
        return $this->mediaEventService->fetchByHashtags($hashtags, $kinds);
    }

    /** @see MediaEventService::fetchByTimeRange() */
    public function getMediaEventsByTimeRange(array $kinds = [20, 21, 22], int $from = 0, int $to = 0, int $limit = 1000): array
    {
        return $this->mediaEventService->fetchByTimeRange($kinds, $from, $to, $limit);
    }

    /** @see NostrRequestExecutor::fetchByTimeRange() */
    public function getCurationEventsByTimeRange(
        array   $kinds = [30004, 30005, 30006],
        int     $from = 0,
        int     $to = 0,
        int     $limit = 500,
        ?string $pubkey = null,
    ): array {
        $extraFilters = $pubkey ? ['authors' => [$pubkey]] : [];
        $relaySet     = $this->relaySetFactory->fromUrls(
            array_slice($this->relayRegistry->getContentRelays(), 0, 3)
        );
        return $this->executor->fetchByTimeRange(
            kinds: $kinds,
            from: $from,
            to: $to,
            limit: $limit,
            defaultDays: 30,
            relaySet: $relaySet,
            extraFilters: $extraFilters,
        );
    }

    /** @see MediaEventService::save() */
    public function saveMediaEvent(object $rawEvent): void
    {
        $this->mediaEventService->save($rawEvent);
    }

    // =========================================================================
    // Social interactions — delegates to SocialEventService
    // =========================================================================

    /** @see SocialEventService::getComments() */
    public function getComments(string $coordinate, ?int $since = null): array
    {
        return $this->socialEventService->getComments($coordinate, $since);
    }

    /** @see SocialEventService::getZaps() */
    public function getZapsForEvent(string $coordinate): array
    {
        return $this->socialEventService->getZaps($coordinate);
    }

    /** @see SocialEventService::getHighlights() */
    public function getArticleHighlights(int $limit = 50): array
    {
        return $this->socialEventService->getHighlights($limit);
    }

    /** @see SocialEventService::getHighlightsForArticle() */
    public function getHighlightsForArticle(string $articleCoordinate, int $limit = 100): array
    {
        return $this->socialEventService->getHighlightsForArticle($articleCoordinate, $limit);
    }

    // =========================================================================
    // User profile — delegates to UserProfileService
    // =========================================================================

    /** @see UserProfileService::getMetadata() */
    public function getPubkeyMetadata(string $pubkey): \stdClass
    {
        return $this->userProfileService->getMetadata($pubkey);
    }

    /** @see UserProfileService::getBatchMetadata() */
    public function getMetadataForPubkeys(array $pubkeys, bool $fallback = true): array
    {
        return $this->userProfileService->getBatchMetadata($pubkeys, $fallback);
    }

    /** @see UserProfileService::getRelays() */
    public function getNpubRelays(string $npub): array
    {
        return $this->userProfileService->getRelays($npub);
    }

    /** @see UserProfileService::getInterests() */
    public function getUserInterests(string $pubkey, ?array $relays = null): array
    {
        return $this->userProfileService->getInterests($pubkey, $relays);
    }

    /** @see UserProfileService::getFollows() */
    public function getUserFollows(string $pubkey, ?array $relays = null): array
    {
        return $this->userProfileService->getFollows($pubkey, $relays);
    }

    /** @see UserProfileService::getBookmarks() */
    public function fetchBookmarks(string $pubkey): array
    {
        return $this->userProfileService->getBookmarks($pubkey);
    }
}
