<?php

namespace App\Service;

use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Factory\ArticleFactory;
use App\Util\NostrPhp\TweakedRequest;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use nostriphant\NIP19\Data;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Key\Key;
use swentel\nostr\Message\EventMessage;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Relay\RelaySet;
use swentel\nostr\Request\Request;
use swentel\nostr\Subscription\Subscription;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class NostrClient
{
    private RelaySet $defaultRelaySet;

    /**
     * List of reputable relays in descending order of reputation
     */
    private const REPUTABLE_RELAYS = [
        'wss://theforest.nostr1.com',
        'wss://nostr.land',
        'wss://purplepag.es',
        'wss://nos.lol',
        'wss://relay.snort.social',
        'wss://relay.damus.io',
        'wss://relay.primal.net',
    ];

    public function __construct(private readonly EntityManagerInterface $entityManager,
                                private readonly ManagerRegistry        $managerRegistry,
                                private readonly ArticleFactory         $articleFactory,
                                private readonly TokenStorageInterface  $tokenStorage,
                                private readonly LoggerInterface        $logger,
                                private readonly CacheItemPoolInterface $npubCache,
                                private readonly NostrRelayPool         $relayPool,
                                private readonly ?string                $nostrDefaultRelay = null)
    {
        // Initialize default relay set using the relay pool to avoid duplicate connections
        // Prefer local relay if configured, otherwise use public relays
        $defaultRelayUrls = [];

        if ($this->nostrDefaultRelay) {
            // Use configured default relay (typically local strfry instance)
            $defaultRelayUrls = [$this->nostrDefaultRelay];
            $this->logger->info('Using configured default Nostr relay', ['relay' => $this->nostrDefaultRelay]);
        } else {
            // Fallback to public relays
            $defaultRelayUrls = [
                'wss://theforest.nostr1.com',
                'wss://nostr.land',
                'wss://relay.primal.net'
            ];
            $this->logger->info('Using public Nostr relays (no default relay configured)');
        }

        $this->defaultRelaySet = new RelaySet();
        foreach ($defaultRelayUrls as $url) {
            $this->defaultRelaySet->addRelay($this->relayPool->getRelay($url));
        }
    }

    /**
     * Creates a RelaySet from a list of relay URLs using the connection pool
     */
    private function createRelaySet(array $relayUrls): RelaySet
    {
        $relaySet = new RelaySet();
        foreach ($relayUrls as $relayUrl) {
            // Use the pool to get persistent relay connections
            $relay = $this->relayPool->getRelay($relayUrl);
            $relaySet->addRelay($relay);
        }
        return $relaySet;
    }

    /**
     * Get top 3 reputable relays from an author's relay list
     */
    private function getTopReputableRelaysForAuthor(string $pubkey, int $limit = 3): array
    {
        try {
            $authorRelays = $this->getNpubRelays($pubkey);
        } catch (\Exception $e) {
            $this->logger->error('Error getting author relays', [
                'pubkey' => $pubkey,
                'error' => $e->getMessage()
            ]);
            // fall through
            $authorRelays = [];
        }
        if (empty($authorRelays)) {
            return self::REPUTABLE_RELAYS; // Default to theforest if no author relays
        }

        $reputableAuthorRelays = [];
        foreach (self::REPUTABLE_RELAYS as $relay) {
            if (in_array($relay, $authorRelays) && count($reputableAuthorRelays) < $limit) {
                $reputableAuthorRelays[] = $relay;
            }
        }

        // If no reputable relays found in author's list, take the top 3 from author's list
        // But make sure they start with wss: and are not localhost
        if (empty($reputableAuthorRelays)) {
            $authorRelays = array_filter($authorRelays, function ($relay) {
                return str_starts_with($relay, 'wss:') && !str_contains($relay, 'localhost');
            });
            return array_slice($authorRelays, 0, $limit);
        }

        return $reputableAuthorRelays;
    }

    /**
     * @throws \Exception
     */
    public function getPubkeyMetadata($pubkey): \stdClass
    {
        // Use relay pool for all relays including purplepag.es, with local relay prioritized
        $relayUrls = $this->relayPool->ensureLocalRelayInList([
            'wss://theforest.nostr1.com',
            'wss://nostr.land',
            'wss://relay.primal.net',
            'wss://purplepag.es'
        ]);
        $relaySet = $this->createRelaySet($relayUrls);

        $this->logger->info('Getting metadata for pubkey ' . $pubkey );
        $request = $this->createNostrRequest(
            kinds: [KindsEnum::METADATA],
            filters: ['authors' => [$pubkey]],
            relaySet: $relaySet
        );

        $events = $this->processResponse($request->send(), function($received) {
            $this->logger->info('Getting metadata for pubkey', ['item' => $received]);
            return $received;
        });

        $this->logger->info('Getting metadata for pubkey', ['response' => $events]);

        if (empty($events)) {
            throw new \Exception('No metadata found for pubkey: ' . $pubkey);
        }

        // Sort by date and return newest
        usort($events, fn($a, $b) => $b->created_at <=> $a->created_at);
        return $events[0];
    }

    public function publishEvent(Event $event, array $relays): array
    {
        $eventMessage = new EventMessage($event);
        // If no relays, fetch relays for user then post to those
        if (empty($relays)) {
            $key = new Key();
            $relays = $this->getTopReputableRelaysForAuthor($key->convertPublicKeyToBech32($event->getPublicKey()), 5);
        } else {
            // Ensure local relay is included when publishing
            $relays = $this->relayPool->ensureLocalRelayInList($relays);
        }

        // Use relay pool instead of creating new Relay instances
        $relaySet = $this->createRelaySet($relays);
        $relaySet->setMessage($eventMessage);
        try {
            $this->logger->info('Publishing event to relays', [
                'event_id' => $event->getId(),
                'relays' => $relays
            ]);
            return $relaySet->send();
        } catch (\Exception $e) {
            $this->logger->error('Error logging publish event', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Long-form Content
     * NIP-23
     */
    public function getLongFormContent($from = null, $to = null): void
    {
        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();
        $filter = new Filter();
        $filter->setKinds([KindsEnum::LONGFORM]);
        $filter->setSince(strtotime('-1 week')); // default
        if ($from !== null) {
            $filter->setSince($from);
        }
        if ($to !== null) {
            $filter->setUntil($to);
        }
        $requestMessage = new RequestMessage($subscriptionId, [$filter]);

        // Create relay set from all reputable relays on record, with local relay prioritized
        $relayUrls = $this->relayPool->ensureLocalRelayInList(self::REPUTABLE_RELAYS);
        $relaySet = $this->createRelaySet($relayUrls);

        $request = new Request($relaySet, $requestMessage);

        // Process the response
        $events = $this->processResponse($request->send(), function($event) {
            return $event;
        });

        if (!empty($events)) {
            foreach ($events as $event) {
                $article = $this->articleFactory->createFromLongFormContentEvent($event);
                // check if event with same eventId already in DB
                $this->saveEachArticleToTheDatabase($article);
            }
        }
    }

    /**
     * @throws \Exception
     */
    public function getLongFormFromNaddr($slug, $relayList, $author, $kind): void
    {
        $this->logger->info('Getting long form from ' . $slug, [
            'relay_list' => $relayList,
            'author' => $author,
            'kind' => $kind
        ]);

        $topAuthorRelays = $this->getTopReputableRelaysForAuthor($author);
        $authorRelaySet = $this->createRelaySet($topAuthorRelays);
        $this->logger->info('Author relays for long form fetch', [
            'author' => $author,
            'from_event' => $this->getNpubRelays($author),
            'relays' => $topAuthorRelays
        ]);

        if (empty($relayList)) {
            $topAuthorRelays = $this->getTopReputableRelaysForAuthor($author);
            $authorRelaySet = $this->createRelaySet($topAuthorRelays);
        } else {
            $authorRelaySet = $this->createRelaySet($relayList);
        }

        try {
            // Create request using the helper method for forest relay set
            $request = $this->createNostrRequest(
                kinds: [$kind],
                filters: [
                    'authors' => [$author],
                    'tag' => ['#d', [$slug]]
                ],
                relaySet: $authorRelaySet
            );

            // Process the response
            $events = $this->processResponse($request->send(), function($event) {
                return $event;
            });

            if (!empty($events)) {
                // Save only the first event (most recent)
                $event = $events[0];
                $wrapper = new \stdClass();
                $wrapper->type = 'EVENT';
                $wrapper->event = $event;
                $this->saveLongFormContent([$wrapper]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Error querying relays', [
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Error querying relays', 0, $e);
        }
    }

    /**
     * Get event by its ID
     *
     * @param string $eventId The event ID
     * @param array $relays Optional array of relay URLs to query
     * @return object|null The event or null if not found
     * @throws \Exception
     */
    public function getEventById(string $eventId, array $relays = []): ?object
    {
        $this->logger->info('Getting event by ID', ['event_id' => $eventId, 'relays' => $relays]);

        // Ensure local relay is included in the relay list
        $relays = $this->relayPool->ensureLocalRelayInList($relays);

        // Merge relays with reputable ones
        $allRelays = array_unique(array_merge($relays, self::REPUTABLE_RELAYS));
        // Loop over reputable relays and bail as soon as you get a valid event back
        foreach ($allRelays as $reputableRelay) {
            $this->logger->info('Trying reputable relay first', ['relay' => $reputableRelay]);
            $request = $this->createNostrRequest(
                kinds: [],
                filters: ['ids' => [$eventId], 'limit' => 1],
                relaySet: $this->createRelaySet([$reputableRelay]),
                stopGap: $eventId
            );
            $events = $this->processResponse($request->send(), function($event) {
                $this->logger->debug('Received event', ['event' => $event]);
                return $event;
            });
            if (!empty($events)) {
                return $events[0];
            }
        }

        // Use provided relays or default if empty
        $relaySet = empty($relays) ? $this->defaultRelaySet : $this->createRelaySet($relays);

        // Create request using the helper method
        $request = $this->createNostrRequest(
            kinds: [],
            filters: ['ids' => [$eventId]],
            relaySet: $relaySet
        );

        // Process the response
        $events = $this->processResponse($request->send(), function($event) {
            $this->logger->debug('Received event', ['event' => $event]);
            return $event;
        });

        if (empty($events)) {
            return null;
        }

        // Return the first matching event
        return $events[0];
    }

    /**
     * Get multiple events by their IDs
     *
     * @param array $eventIds Array of event IDs
     * @param array $relays Optional array of relay URLs to query
     * @return array Array of events indexed by ID
     * @throws \Exception
     */
    public function getEventsByIds(array $eventIds, array $relays = []): array
    {
        if (empty($eventIds)) {
            return [];
        }

        $this->logger->info('Getting events by IDs', ['event_ids' => $eventIds, 'relays' => $relays]);

        // Ensure local relay is included
        if (!empty($relays)) {
            $relays = $this->relayPool->ensureLocalRelayInList($relays);
        }

        // Use provided relays or default if empty
        $relaySet = empty($relays) ? $this->defaultRelaySet : $this->createRelaySet($relays);

        // Create request using the helper method
        $request = $this->createNostrRequest(
            kinds: [],
            filters: ['ids' => $eventIds],
            relaySet: $relaySet
        );

        // Process the response
        $events = $this->processResponse($request->send(), function($event) {
            $this->logger->debug('Received event', ['event' => $event]);
            return $event;
        });

        // Index events by ID
        $eventsMap = [];
        foreach ($events as $event) {
            $eventsMap[$event->id] = $event;
        }

        return $eventsMap;
    }

    /**
     * Fetch event by naddr
     *
     * @param array $decoded Decoded naddr data
     * @return object|null The event or null if not found
     * @throws \Exception
     */
    public function getEventByNaddr(array $decoded): ?object
    {
        $this->logger->info('Getting event by naddr', ['decoded' => $decoded]);

        // Extract required fields from decoded data
        $kind = $decoded['kind'] ?? 30023; // Default to long-form content
        $pubkey = $decoded['pubkey'] ?? '';
        $identifier = $decoded['identifier'] ?? '';
        $relays = $decoded['relays'] ?? [];

        if (empty($pubkey) || empty($identifier)) {
            return null;
        }

        // Try author's relays first
        $authorRelays = empty($relays) ? $this->getTopReputableRelaysForAuthor($pubkey) : $relays;
        $relaySet = $this->createRelaySet($authorRelays);

        // Create request using the helper method
        $request = $this->createNostrRequest(
            kinds: [$kind],
            filters: [
                'authors' => [$pubkey],
                'tag' => ['#d', [$identifier]]
            ],
            relaySet: $relaySet
        );

        // Process the response
        $events = $this->processResponse($request->send(), function($event) {
            return $event;
        });

        if (!empty($events)) {
            return $events[0];
        }

        // Try default relays as fallback
        $request = $this->createNostrRequest(
            kinds: [$kind],
            filters: [
                'authors' => [$pubkey],
                'tag' => ['#d', [$identifier]]
            ]
        );

        $events = $this->processResponse($request->send(), function($event) {
            return $event;
        });

        return !empty($events) ? $events[0] : null;
    }

    private function saveLongFormContent(mixed $filtered): void
    {
        foreach ($filtered as $wrapper) {
            $article = $this->articleFactory->createFromLongFormContentEvent($wrapper->event);
            // check if event with same eventId already in DB
            $this->saveEachArticleToTheDatabase($article);
        }
    }

    /**
     * @throws \Exception
     */
    public function getNpubRelays($npub): array
    {
        $cacheKey = 'npub_relays_' . $npub;
        try {
            // $this->npubCache->deleteItem($cacheKey);
            $cachedItem = $this->npubCache->getItem($cacheKey);
            if ($cachedItem->isHit()) {
                $this->logger->debug('Using cached relays for npub', ['npub' => $npub]);
                return $cachedItem->get();
            }
        } catch (\Exception $e) {
            $this->logger->warning('Cache error', ['error' => $e->getMessage()]);
        }

        $relays = new RelaySet();
        $relays->createFromUrls(self::REPUTABLE_RELAYS);

        // Get relays
        $request = $this->createNostrRequest(
            kinds: [KindsEnum::RELAY_LIST->value],
            filters: ['authors' => [$npub]],
            relaySet: $relays
        );
        $response = $this->processResponse($request->send(), function($received) {
            return $received;
        });
        if (empty($response)) {
            return [];
        }
        // Sort by date and use newest
        usort($response, fn($a, $b) => $b->created_at <=> $a->created_at);
        // Process tags of the $response[0] and extract relays
        $relays = [];
        foreach ($response[0]->tags as $tag) {
            if ($tag[0] === 'r') {
                $relays[] = $tag[1];
            }
        }
        // Remove duplicates, localhost and any non-wss relays
        $relays = array_filter(array_unique($relays), function ($relay) {
            return str_starts_with($relay, 'wss:') && !str_contains($relay, 'localhost');
        });

        // Cache the result
        try {
            $cachedItem = $this->npubCache->getItem($cacheKey);
            $cachedItem->set($relays);
            $cachedItem->expiresAfter(3600); // 1 hour
            $this->npubCache->save($cachedItem);
        } catch (\Exception $e) {
            $this->logger->warning('Cache save error', ['error' => $e->getMessage()]);
        }

        return $relays;
    }

    /**
     * Get comments for a specific coordinate
     *
     * @param string $coordinate The event coordinate (kind:pubkey:identifier)
     * @return array Array of comment events
     * @throws \Exception
     */
    public function getComments(string $coordinate, ?int $since = null): array
    {
        $this->logger->info('Getting comments for coordinate', ['coordinate' => $coordinate]);

        // Get author from coordinate, then relays
        $parts = explode(':', $coordinate, 3);
        if (count($parts) < 3) {
            throw new \InvalidArgumentException('Invalid coordinate format, expected kind:pubkey:identifier');
        }
        $kind = (int)$parts[0];
        $pubkey = $parts[1];
        $identifier = end($parts);

        // Use local relay only if configured, otherwise use author relays
        if ($this->nostrDefaultRelay) {
            $authorRelays = [$this->nostrDefaultRelay];
            $this->logger->info('Using local relay for comments fetch', [
                'relay' => $this->nostrDefaultRelay,
                'coordinate' => $coordinate
            ]);
        } else {
            // Fallback to author relays when no local relay is configured
            $authorRelays = $this->getTopReputableRelaysForAuthor($pubkey);
            $this->logger->info('Using author relays for comments fetch', [
                'coordinate' => $coordinate,
                'relay_count' => count($authorRelays)
            ]);
        }

        // Build the request message
        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();
        $filter = new Filter();
        $filter->setKinds([
            KindsEnum::COMMENTS->value,
            // KindsEnum::ZAP_RECEIPT->value  // Not yet
        ]);
        $filter->setTag('#A', [$coordinate]);

        if (is_int($since) && $since > 0) {
            $filter->setSince($since);
        }

        $requestMessage = new RequestMessage($subscriptionId, [$filter]);

        // Use the relay pool to send the request
        // Pass subscription ID so the pool can send CLOSE message after receiving responses
        $responses = $this->relayPool->sendToRelays(
            $authorRelays,
            fn() => $requestMessage,
            30, // timeout in seconds
            $subscriptionId // close subscription after receiving responses
        );

        // Process the response and deduplicate by eventId
        $uniqueEvents = [];
        $this->processResponse($responses, function($event) use (&$uniqueEvents) {
            $this->logger->debug('Received comment event', ['event_id' => $event->id]);
            $uniqueEvents[$event->id] = $event;
            return null;
        });

        return array_values($uniqueEvents);
    }

    /**
     * Get zap events for a specific event
     *
     * @param string $coordinate The event coordinate (kind:pubkey:identifier)
     * @return array Array of zap events
     * @throws \Exception
     */
    public function getZapsForEvent(string $coordinate): array
    {
        $this->logger->info('Getting zaps for coordinate', ['coordinate' => $coordinate]);

        // Parse the coordinate to get pubkey
        $parts = explode(':', $coordinate, 3);
        $pubkey = $parts[1];

        // Get author's relays for better chances of finding zaps (includes local relay)
        $authorRelays = $this->getTopReputableRelaysForAuthor($pubkey);
        $relaySet = $this->createRelaySet($authorRelays);

        // Create request using the helper method
        // Zaps are kind 9735
        $request = $this->createNostrRequest(
            kinds: [KindsEnum::ZAP_RECEIPT],
            filters: ['tag' => ['#a', [$coordinate]]],
            relaySet: $relaySet
        );

        // Process the response
        return $this->processResponse($request->send(), function($event) {
            $this->logger->debug('Received zap event', ['event_id' => $event->id]);
            return $event;
        });
    }

    /**
     * @throws \Exception
     */
    public function getLongFormContentForPubkey(string $ident, ?int $since = null, ?int $kind = KindsEnum::LONGFORM->value ): array
    {
        // Use local relay only if configured, otherwise use author relays
        if ($this->nostrDefaultRelay) {
            $relaySet = $this->defaultRelaySet;
            $this->logger->info('Using local relay for article fetch', [
                'relay' => $this->nostrDefaultRelay,
                'pubkey' => $ident
            ]);
        } else {
            // Fallback to author relays when no local relay is configured
            $authorRelays = $this->getTopReputableRelaysForAuthor($ident);
            $relaySet = empty($authorRelays) ? $this->defaultRelaySet : $this->createRelaySet($authorRelays);
            $this->logger->info('Using author relays for article fetch', [
                'pubkey' => $ident,
                'relay_count' => count($authorRelays)
            ]);
        }

        // Create request using the helper method
        $filters = [
            'authors' => [$ident],
            'limit' => 20 // default limit
        ];
        if ($since !== null && $since > 0) {
            $filters['since'] = $since;
        }

        $request = $this->createNostrRequest(
            kinds: [$kind],
            filters: $filters,
            relaySet: $relaySet
        );

        // Process the response using the helper method
        return $this->processResponse($request->send(), function($event) {
            $article = $this->articleFactory->createFromLongFormContentEvent($event);
            // Save each article to the database
            $this->saveEachArticleToTheDatabase($article);
            return $article; // Return the article so it gets added to the results array
        });
    }

    /**
     * Get picture events (kind 20) for a specific author
     * @throws \Exception
     */
    public function getPictureEventsForPubkey(string $ident, int $limit = 20): array
    {
        // Use local relay only if configured, otherwise use author relays
        if ($this->nostrDefaultRelay) {
            $relaySet = $this->defaultRelaySet;
            $this->logger->info('Using local relay for picture events fetch', [
                'relay' => $this->nostrDefaultRelay,
                'pubkey' => $ident
            ]);
        } else {
            // Fallback to author relays when no local relay is configured
            $authorRelays = $this->getTopReputableRelaysForAuthor($ident);
            $relaySet = empty($authorRelays) ? $this->defaultRelaySet : $this->createRelaySet($authorRelays);
        }

        // Create request for kind 20 (picture events)
        $request = $this->createNostrRequest(
            kinds: [20], // NIP-68 Picture events
            filters: [
                'authors' => [$ident],
                'limit' => $limit
            ],
            relaySet: $relaySet
        );

        // Process the response and return raw events
        return $this->processResponse($request->send(), function($event) {
            return $event; // Return the raw event
        });
    }

    /**
     * Get video shorts (kind 22) for a given pubkey
     * @throws \Exception
     */
    public function getVideoShortsForPubkey(string $ident, int $limit = 20): array
    {
        // Use local relay only if configured, otherwise use author relays
        if ($this->nostrDefaultRelay) {
            $relaySet = $this->defaultRelaySet;
            $this->logger->info('Using local relay for video shorts fetch', [
                'relay' => $this->nostrDefaultRelay,
                'pubkey' => $ident
            ]);
        } else {
            // Fallback to author relays when no local relay is configured
            $authorRelays = $this->getTopReputableRelaysForAuthor($ident);
            $relaySet = empty($authorRelays) ? $this->defaultRelaySet : $this->createRelaySet($authorRelays);
        }

        // Create request for kind 22 (short video events)
        $request = $this->createNostrRequest(
            kinds: [22], // NIP-71 Short video events
            filters: [
                'authors' => [$ident],
                'limit' => $limit
            ],
            relaySet: $relaySet
        );

        // Process the response and return raw events
        return $this->processResponse($request->send(), function($event) {
            return $event; // Return the raw event
        });
    }

    /**
     * Get normal video events (kind 21) for a given pubkey
     * @throws \Exception
     */
    public function getNormalVideosForPubkey(string $ident, int $limit = 20): array
    {
        // Use local relay only if configured, otherwise use author relays
        if ($this->nostrDefaultRelay) {
            $relaySet = $this->defaultRelaySet;
            $this->logger->info('Using local relay for normal videos fetch', [
                'relay' => $this->nostrDefaultRelay,
                'pubkey' => $ident
            ]);
        } else {
            // Fallback to author relays when no local relay is configured
            $authorRelays = $this->getTopReputableRelaysForAuthor($ident);
            $relaySet = empty($authorRelays) ? $this->defaultRelaySet : $this->createRelaySet($authorRelays);
        }

        // Create request for kind 21 (normal video events)
        $request = $this->createNostrRequest(
            kinds: [21], // NIP-71 Normal video events
            filters: [
                'authors' => [$ident],
                'limit' => $limit
            ],
            relaySet: $relaySet
        );

        // Process the response and return raw events
        return $this->processResponse($request->send(), function($event) {
            return $event; // Return the raw event
        });
    }

    /**
     * Get all media events (pictures and videos) for a given pubkey in a single request
     * This is more efficient than making 3 separate requests
     * @throws \Exception
     */
    public function getAllMediaEventsForPubkey(string $ident, int $limit = 30): array
    {
        // Use local relay only if configured, otherwise use author relays
        if ($this->nostrDefaultRelay) {
            $relaySet = $this->defaultRelaySet;
            $this->logger->info('Using local relay for all media events fetch', [
                'relay' => $this->nostrDefaultRelay,
                'pubkey' => $ident
            ]);
        } else {
            // Fallback to author relays when no local relay is configured
            $authorRelays = $this->getTopReputableRelaysForAuthor($ident);
            $relaySet = empty($authorRelays) ? $this->defaultRelaySet : $this->createRelaySet($authorRelays);
        }

        // Create request for all media kinds (20, 21, 22) in ONE request
        $request = $this->createNostrRequest(
            kinds: [20, 21, 22], // NIP-68 Pictures, NIP-71 Videos (normal and shorts)
            filters: [
                'authors' => [$ident],
                'limit' => $limit
            ],
            relaySet: $relaySet
        );

        // Process the response and return raw events
        return $this->processResponse($request->send(), function($event) {
            return $event; // Return the raw event
        });
    }

    /**
     * Get recent media events (pictures and videos) from the last 3 days
     * Fetches from all relays without filtering by author
     * @throws \Exception
     */
    public function getRecentMediaEvents(int $limit = 100): array
    {
        // Create request for all media kinds (20, 21, 22) from the last 3 days
        $request = $this->createNostrRequest(
            kinds: [20, 21, 22], // NIP-68 Pictures, NIP-71 Videos (normal and shorts)
            filters: [
                'limit' => $limit
            ],
            relaySet: $this->defaultRelaySet
        );

        // Process the response and return raw events
        return $this->processResponse($request->send(), function($event) {
            return $event; // Return the raw event
        });
    }

    /**
     * Get media events filtered by specific hashtags
     * @throws \Exception
     */
    public function getMediaEventsByHashtags(array $hashtags): array
    {
        $allEvents = [];

        // Fetch events for each hashtag

            $request = $this->createNostrRequest(
                kinds: [20], // NIP-68 Pictures; consider expanding to 21/22 later
                filters: [
                    'tag' => ['#t', $hashtags],
                    'limit' => 500 // Fetch up to 100 per hashtag
                ],
                relaySet: $this->createRelaySet(['wss://theforest.nostr1.com'])
            );

            $events = $this->processResponse($request->send(), function($event) {
                return $event;
            });

            $allEvents = array_merge($allEvents, $events);


        return $allEvents;
    }

    public function getArticles(array $slugs): array
    {
        $articles = [];
        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();
        $filter = new Filter();
        $filter->setKinds([KindsEnum::LONGFORM]);
        $filter->setTag('#d', $slugs);
        $requestMessage = new RequestMessage($subscriptionId, [$filter]);

        try {
            $request = new Request($this->defaultRelaySet, $requestMessage);
            $response = $request->send();
            $hasEvents = false;

            // Check if we got any events
            foreach ($response as $value) {
                foreach ($value as $item) {
                    if ($item->type === 'EVENT') {
                        if (!isset($articles[$item->event->id])) {
                            $articles[$item->event->id] = $item->event;
                            $hasEvents = true;
                        }
                    }
                }
            }

            // If no articles found, try the default relay set
            if (!$hasEvents && !empty($slugs)) {
                $this->logger->info('No results from theforest, trying default relays');

                $request = new Request($this->defaultRelaySet, $requestMessage);
                $response = $request->send();

                foreach ($response as $value) {
                    foreach ($value as $item) {
                        if ($item->type === 'EVENT') {
                            if (!isset($articles[$item->event->id])) {
                                $articles[$item->event->id] = $item->event;
                            }
                        } elseif (in_array($item->type, ['AUTH', 'ERROR', 'NOTICE'])) {
                            $this->logger->error('An error while getting articles.', ['response' => $item]);
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error querying relays', [
                'error' => $e->getMessage()
            ]);

            // Fall back to default relay set
            $request = new Request($this->defaultRelaySet, $requestMessage);
            $response = $request->send();

            foreach ($response as $value) {
                foreach ($value as $item) {
                    if ($item->type === 'EVENT') {
                        if (!isset($articles[$item->event->id])) {
                            $articles[$item->event->id] = $item->event;
                        }
                    }
                }
            }
        }

        return $articles;
    }

    /**
     * Fetch articles by coordinates (kind:author:slug)
     * Returns a map of coordinate => event for successful fetches
     *
     * @param array $coordinates Array of coordinates in format kind:author:slug
     * @return array Map of coordinate => event
     * @throws \Exception
     */
    public function getArticlesByCoordinates(array $coordinates): array
    {
        $articlesMap = [];

        foreach ($coordinates as $coordinate) {
            $parts = explode(':', $coordinate);

            if (count($parts) !== 3) {
                $this->logger->warning('Invalid coordinate format', ['coordinate' => $coordinate]);
                continue;
            }

            $kind = (int)$parts[0];
            $pubkey = $parts[1];
            $slug = $parts[2];

            // Try to get relays associated with the author first
            $relayList = [];
            try {
                // Get relays where the author publishes
                $authorRelays = $this->getTopReputableRelaysForAuthor($pubkey);
                if (!empty($authorRelays)) {
                    $relayList = $authorRelays;
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to get author relays', [
                    'pubkey' => $pubkey,
                    'error' => $e->getMessage()
                ]);
                // Continue with default relays
            }

            // If no author relays found, add default relay
            if (empty($relayList)) {
                $relayList = [self::REPUTABLE_RELAYS[0]];
            }

            // Ensure we use a RelaySet
            $relaySet = $this->createRelaySet($relayList);

            // Create subscription and filter
            $subscription = new Subscription();
            $subscriptionId = $subscription->setId();
            $filter = new Filter();
            $filter->setKinds([$kind]);
            $filter->setAuthors([$pubkey]);
            $filter->setTag('#d', [$slug]);
            $requestMessage = new RequestMessage($subscriptionId, [$filter]);

            try {
                $request = new Request($relaySet, $requestMessage);
                $response = $request->send();
                $found = false;

                // Check responses from each relay
                foreach ($response as $value) {
                    foreach ($value as $item) {
                        if ($item->type === 'EVENT') {
                            $articlesMap[$coordinate] = $item->event;
                            $found = true;
                            break 2; // Found what we need, exit both loops
                        }
                    }
                }

                // If still not found, try with default relay set as fallback
                if (!$found) {
                    $this->logger->info('Article not found in author relays, trying default relays', [
                        'coordinate' => $coordinate
                    ]);

                    $request = new Request($this->defaultRelaySet, $requestMessage);
                    $response = $request->send();

                    foreach ($response as $value) {
                        foreach ($value as $item) {
                            if ($item->type === 'EVENT') {
                                $articlesMap[$coordinate] = $item->event;
                                break 2;
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->logger->error('Error fetching article', [
                    'coordinate' => $coordinate,
                    'error' => $e->getMessage()
                ]);
            }
        }

        return $articlesMap;
    }

    /**
     * Get article highlights (NIP-84) - kind 9802 events that reference articles
     * @throws \Exception
     */
    public function getArticleHighlights(int $limit = 50): array
    {
        $this->logger->info('Fetching article highlights from default relay');

        // Use relay pool to send request
        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();
        $filter = new Filter();
        $filter->setKinds([9802]); // NIP-84 highlights
        $filter->setLimit($limit);
        $filter->setSince(strtotime('-7 days')); // Last 7 days

        $requestMessage = new RequestMessage($subscriptionId, [$filter]);

        // Use only the configured default relay
        $relayUrls = $this->nostrDefaultRelay
            ? [$this->nostrDefaultRelay]
            : [($this->relayPool->getDefaultRelays()[0] ?? null)];
        $relayUrls = array_filter($relayUrls); // Remove nulls if fallback fails

        $responses = $this->relayPool->sendToRelays(
            $relayUrls,
            fn() => $requestMessage,
            30,
            $subscriptionId
        );

        // Process the response and deduplicate by eventId
        $uniqueEvents = [];
        $this->processResponse($responses, function($event) use (&$uniqueEvents) {
            // Filter to only include highlights that reference articles
            $hasArticleRef = false;
            foreach ($event->tags ?? [] as $tag) {
                if (is_array($tag) && count($tag) >= 2) {
                    if (in_array($tag[0], ['a', 'A'])) {
                        // Check if it references a kind 30023 (article)
                        if (str_starts_with($tag[1] ?? '', '30023:')) {
                            $hasArticleRef = true;
                            break;
                        }
                    }
                }
            }

            if ($hasArticleRef) {
                $this->logger->debug('Received article highlight event', ['event_id' => $event->id]);
                $uniqueEvents[$event->id] = $event;
            }
            return null;
        });

        return array_values($uniqueEvents);
    }

    /**
     * Get highlights for a specific article
     * @throws \Exception
     */
    public function getHighlightsForArticle(string $articleCoordinate, int $limit = 100): array
    {
        $this->logger->info('Fetching highlights for article', ['coordinate' => $articleCoordinate]);

        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();
        $filter = new Filter();
        $filter->setKinds([9802]); // NIP-84 highlights
        $filter->setLimit($limit);
        $filter->setTags(['#a' => [$articleCoordinate]]);

        $requestMessage = new RequestMessage($subscriptionId, [$filter]);

        // Prefer ONLY the local relay if configured
        if ($this->nostrDefaultRelay) {
            $relayUrls = [$this->nostrDefaultRelay];
            $this->logger->info('Using local relay for highlights fetch', [
                'relay' => $this->nostrDefaultRelay,
                'coordinate' => $articleCoordinate
            ]);
        } else {
            // Fallback – use first default relay only (keep narrow to avoid huge queries)
            $defaultRelays = $this->relayPool->getDefaultRelays();
            $relayUrls = empty($defaultRelays) ? [] : [$defaultRelays[0]];
            $this->logger->info('Using fallback public relay for highlights fetch', [
                'coordinate' => $articleCoordinate,
                'relay' => $relayUrls[0] ?? 'none'
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
        $this->processResponse($responses, function($event) use (&$uniqueEvents) {
            $this->logger->debug('Received highlight event for article', ['event_id' => $event->id]);
            $uniqueEvents[$event->id] = $event;
            return null;
        });

        return array_values($uniqueEvents);
    }

    public function getLatestLongFormArticles(int $limit = 50, ?int $since = null): array
    {
        // Prefer ONLY the local relay if configured; otherwise use the default relay set
        if ($this->nostrDefaultRelay) {
            $relaySet = $this->createRelaySet([$this->nostrDefaultRelay]);
            $this->logger->info('Fetching latest long-form articles from local relay', [
                'relay' => $this->nostrDefaultRelay,
                'limit' => $limit,
                'since' => $since
            ]);
        } else {
            $relaySet = $this->defaultRelaySet;
            $this->logger->info('Fetching latest long-form articles from default relay set (no local relay configured)', [
                'limit' => $limit,
                'since' => $since
            ]);
        }

        $filters = [ 'limit' => $limit ];
        if ($since !== null && $since > 0) {
            $filters['since'] = $since;
        }

        try {
            $request = $this->createNostrRequest(
                kinds: [KindsEnum::LONGFORM->value],
                filters: $filters,
                relaySet: $relaySet
            );

            $events = $this->processResponse($request->send(), function($event) {
                try {
                    $article = $this->articleFactory->createFromLongFormContentEvent($event);
                    // Persist newest revision if not saved yet
                    $this->saveEachArticleToTheDatabase($article);
                    return $article;
                } catch (\Throwable $e) {
                    $this->logger->error('Failed converting event to Article', [
                        'error' => $e->getMessage(),
                        'event_id' => $event->id ?? null
                    ]);
                    return null;
                }
            });
        } catch (\Throwable $e) {
            $this->logger->error('Error fetching latest long-form articles', [ 'error' => $e->getMessage() ]);
            return [];
        }

        // Filter out nulls
        $articles = array_filter($events, fn($a) => $a instanceof Article);

        // Deduplicate by slug keeping latest createdAt
        $bySlug = [];
        foreach ($articles as $article) {
            $slug = $article->getSlug();
            if ($slug === '') { continue; }
            if (!isset($bySlug[$slug]) || $article->getCreatedAt() > $bySlug[$slug]->getCreatedAt()) {
                $bySlug[$slug] = $article;
            }
        }

        // Sort descending by createdAt
        $deduped = array_values($bySlug);
        usort($deduped, fn($a, $b) => $b->getCreatedAt() <=> $a->getCreatedAt());

        return $deduped;
    }

    private function createNostrRequest(array $kinds, array $filters = [], ?RelaySet $relaySet = null, $stopGap = null ): TweakedRequest
    {
        $subscription = new Subscription();
        $filter = new Filter();
        if (!empty($kinds)) {
            $filter->setKinds($kinds);
        }

        foreach ($filters as $key => $value) {
            $method = 'set' . ucfirst($key);
            if (method_exists($filter, $method)) {
                // If it's tags, we need to handle it differently
                if ($key === 'tag') {
                   $filter->setTag($value[0], $value[1]);
                } else {
                    // Call the method with the value
                    $filter->$method($value);
                }
            }
        }
        $this->logger->info('Relay set for request', ['relays' => $relaySet ? $relaySet->getRelays() : 'default']);

        $requestMessage = new RequestMessage($subscription->getId(), [$filter]);
        return (new TweakedRequest(
            $relaySet ?? $this->defaultRelaySet,
            $requestMessage,
            $this->logger
        ))->stopOnEventId($stopGap);
    }

    private function processResponse(array $response, callable $eventHandler): array
    {
        $results = [];
        foreach ($response as $relayUrl => $relayRes) {
            // Skip if the relay response is an Exception
            if ($relayRes instanceof \Exception) {
                $this->logger->error('Relay error', [
                    'relay' => $relayUrl,
                    'error' => $relayRes->getMessage()
                ]);
                continue;
            }

            $this->logger->debug('Processing relay response', [
                'relay' => $relayUrl,
                'response' => $relayRes
            ]);

            foreach ($relayRes as $item) {
                try {
                    if (!is_object($item)) {
                        $this->logger->warning('Invalid response item from ' . $relayUrl , [
                            'relay' => $relayUrl,
                            'item' => $item
                        ]);
                        continue;
                    }

                    switch ($item->type) {
                        case 'EVENT':
                            $this->logger->debug('Processing event', [
                                'relay' => $relayUrl,
                                'event_id' => $item->event->id ?? 'unknown'
                            ]);
                            $result = $eventHandler($item->event);
                            if ($result !== null) {
                                $results[] = $result;
                            }
                            break;
                        case 'AUTH':
                            $this->logger->info('Relay required authentication (handled during request)', [
                                'relay' => $relayUrl,
                                'response' => $item
                            ]);
                            break;
                        case 'ERROR':
                        case 'NOTICE':
                            $this->logger->warning('Relay error/notice', [
                                'relay' => $relayUrl,
                                'type' => $item->type,
                                'message' => $item->message ?? 'No message'
                            ]);
                            break;
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Error processing event from relay', [
                        'relay' => $relayUrl,
                        'error' => $e->getMessage()
                    ]);
                    continue; // Skip this item but continue processing others
                }
            }
        }
        return $results;
    }

    /**
     * @param Article $article
     * @return void
     */
    public function saveEachArticleToTheDatabase(Article $article): void
    {
        $saved = $this->entityManager->getRepository(Article::class)->findOneBy(['eventId' => $article->getEventId()]);
        if (!$saved) {
            try {
                $this->logger->info('Saving article', ['article' => $article]);
                $this->entityManager->persist($article);
                $this->entityManager->flush();
            } catch (\Exception $e) {
                $this->logger->error($e->getMessage());
                $this->managerRegistry->resetManager();
            }
        }
    }

    /**
     * @param mixed $descriptor
     * @return Event|null
     */
    public function getEventFromDescriptor(mixed $descriptor): ?\stdClass
    {
        // Descriptor is an stdClass with properties: type and decoded
        if (is_object($descriptor) && isset($descriptor->type, $descriptor->decoded)) {
            // construct a request from the descriptor to fetch the event
            /** @var Data $ata */
            $data = json_decode($descriptor->decoded);
            // If id is set, search by id and kind
            if (isset($data->id)) {
                $request = $this->createNostrRequest(
                    kinds: [$data->kind],
                    filters: ['e' => [$data->id]],
                    relaySet: $this->defaultRelaySet
                );
            } else {
                $request = $this->createNostrRequest(
                    kinds: [$data->kind],
                    filters: ['authors' => [$data->pubkey], 'd' => [$data->identifier]],
                    relaySet: $this->defaultRelaySet
                );
            }

            $events = $this->processResponse($request->send(), function($received) {
                $this->logger->info('Getting event', ['item' => $received]);
                return $received;
            });

            if (!empty($events)) {
                // Return the first event found
                return $events[0];
            } else {
                $this->logger->warning('No events found for descriptor', ['descriptor' => $descriptor]);
                return null;
            }
        } else {
            $this->logger->error('Invalid descriptor format', ['descriptor' => $descriptor]);
            return null;
        }
    }

    /**
     * Fetch the latest interest list (kind 10015) for a pubkey and return the 't' tags.
     */
    public function getUserInterests(string $pubkey): array
    {
        $request = $this->createNostrRequest(
            kinds: [10015],
            filters: ['authors' => [$pubkey]],
            relaySet: $this->defaultRelaySet
        );

        $events = $this->processResponse($request->send(), function($received) use ($pubkey) {
            $this->logger->info('Getting interests for pubkey', ['pubkey' => $pubkey, 'item' => $received]);
            return $received;
        });

        if (empty($events)) {
            return [];
        }

        // Sort by created_at descending and take the latest
        usort($events, fn($a, $b) => $b->created_at <=> $a->created_at);
        $latest = $events[0];

        $tTags = [];
        foreach ($latest->tags as $tag) {
            if (is_array($tag) && isset($tag[0]) && $tag[0] === 't' && isset($tag[1])) {
                $tTags[] = strtolower(trim($tag[1]));
            }
        }

        return $tTags;
    }

    /**
     * Batch fetch metadata for multiple pubkeys
     * Queries local relay first, then fallback to reputable relays for any missing metadata.
     *
     * @param array $pubkeys Array of pubkeys (hex-encoded) to fetch metadata for
     * @param bool $fallback Whether to fallback to reputable relays if metadata is missing from local relay
     * @return array Map of pubkey => metadata event
     * @throws \Exception
     */
    public function getMetadataForPubkeys(array $pubkeys, bool $fallback = true): array
    {
        // Deduplicate & sanitize
        $pubkeys = array_values(array_unique(array_filter($pubkeys, fn($p) => is_string($p) && strlen($p) === 64)));
        if (empty($pubkeys)) {
            return [];
        }

        $this->logger->info('Batch fetching metadata', [
            'count' => count($pubkeys),
            'fallback' => $fallback,
        ]);

        $results = [];
        $missing = $pubkeys;

        // Helper to process events list and keep newest per pubkey
        $process = function(array $events) use (&$results, &$missing) {
            foreach ($events as $event) {
                $pubkey = $event->pubkey ?? null;
                if (!$pubkey) { continue; }
                // Keep only newest
                if (!isset($results[$pubkey]) || ($event->created_at ?? 0) > ($results[$pubkey]->created_at ?? 0)) {
                    $results[$pubkey] = $event;
                }
            }
            // Update missing list
            $missing = array_values(array_diff($missing, array_keys($results)));
        };

        // First pass: local relay only
        try {
            if ($this->nostrDefaultRelay) {
                $relaySet = $this->createRelaySet([$this->nostrDefaultRelay]);
                $request = $this->createNostrRequest(
                    kinds: [KindsEnum::METADATA->value],
                    filters: [ 'authors' => $missing, 'limit' => count($missing) ],
                    relaySet: $relaySet
                );
                $events = $this->processResponse($request->send(), fn($e) => $e);
                $process($events);
                $this->logger->info('Local relay metadata fetch complete', [
                    'found' => count($results),
                    'remaining' => count($missing)
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Local relay batch metadata fetch failed', ['error' => $e->getMessage()]);
        }

        // Fallback pass: reputable relays (group remaining into chunks to reduce payload size)
        if ($fallback && !empty($missing)) {
            $chunks = array_chunk($missing, 50); // chunk size adjustable
            foreach ($chunks as $chunk) {
                if (empty($chunk)) { continue; }
                try {
                    $relaySet = $this->createRelaySet(self::REPUTABLE_RELAYS);
                    $request = $this->createNostrRequest(
                        kinds: [KindsEnum::METADATA->value],
                        filters: [ 'authors' => $chunk, 'limit' => count($chunk) ],
                        relaySet: $relaySet
                    );
                    $events = $this->processResponse($request->send(), fn($e) => $e);
                    $process($events);
                    $this->logger->info('Fallback metadata chunk fetched', [
                        'chunk_size' => count($chunk),
                        'found_total' => count($results),
                        'remaining' => count($missing)
                    ]);
                    if (empty($missing)) { break; }
                } catch (\Throwable $e) {
                    $this->logger->warning('Fallback metadata chunk failed', [
                        'error' => $e->getMessage(),
                        'chunk_size' => count($chunk)
                    ]);
                }
            }
        }

        // Optional: cache individual metadata events
        foreach ($results as $pubkey => $event) {
            try {
                $cacheKey = 'pubkey_meta_' . $pubkey;
                $cachedItem = $this->npubCache->getItem($cacheKey);
                if (!$cachedItem->isHit() || ($event->created_at ?? 0) > ($cachedItem->get()->created_at ?? 0)) {
                    $cachedItem->set($event);
                    $cachedItem->expiresAfter(84000); // 24 hours
                    $this->npubCache->save($cachedItem);
                }
            } catch (\Throwable $e) {
                // Non-critical
            }
        }

        return $results; // map pubkey => metadata event
    }
}
