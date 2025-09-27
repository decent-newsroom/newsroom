<?php

namespace App\Service;

use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Factory\ArticleFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use nostriphant\NIP19\Data;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event;
use swentel\nostr\Filter\Filter;
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
    private const array REPUTABLE_RELAYS = [
        'wss://theforest.nostr1.com',
        'wss://relay.damus.io',
        'wss://relay.primal.net',
        'wss://nos.lol',
        'wss://relay.snort.social',
        'wss://nostr.land',
        'wss://purplepag.es',
    ];

    public function __construct(private readonly EntityManagerInterface $entityManager,
                                private readonly ManagerRegistry $managerRegistry,
                                private readonly ArticleFactory $articleFactory,
                                private readonly TokenStorageInterface $tokenStorage,
                                private readonly LoggerInterface $logger)
    {
        $this->defaultRelaySet = new RelaySet();
        $this->defaultRelaySet->addRelay(new Relay('wss://theforest.nostr1.com')); // public aggregator relay
        $this->defaultRelaySet->addRelay(new Relay('wss://relay.damus.io')); // public aggregator relay
        $this->defaultRelaySet->addRelay(new Relay('wss://relay.primal.net')); // public aggregator relay
    }

    /**
     * Creates a RelaySet from a list of relay URLs
     */
    private function createRelaySet(array $relayUrls): RelaySet
    {
        $relaySet = $this->defaultRelaySet;
        foreach ($relayUrls as $relayUrl) {
            $relaySet->addRelay(new Relay($relayUrl));
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
            return [self::REPUTABLE_RELAYS[0]]; // Default to theforest if no author relays
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
    public function getNpubMetadata($npub): \stdClass
    {
        $relaySet = $this->defaultRelaySet;
        $relaySet->addRelay(new Relay('wss://profiles.nostr1.com')); // profile aggregator
        $this->logger->info('Getting metadata for npub', ['npub' => $npub]);
        // Npubs are converted to hex for the request down the line
        $request = $this->createNostrRequest(
            kinds: [KindsEnum::METADATA],
            filters: ['authors' => [$npub]],
            relaySet: $relaySet
        );

        $events = $this->processResponse($request->send(), function($received) {
            $this->logger->info('Getting metadata for npub', ['item' => $received]);
            return $received;
        });

        $this->logger->info('Getting metadata for npub', ['response' => $events]);

        if (empty($events)) {
            throw new \Exception('No metadata found for npub: ' . $npub);
        }

        // Sort by date and return newest
        usort($events, fn($a, $b) => $b->created_at <=> $a->created_at);
        return $events[0];
    }

    public function getNpubLongForm($npub): void
    {
        $subscription = new Subscription();
        $subscriptionId = $subscription->setId();
        $filter = new Filter();
        $filter->setKinds([KindsEnum::LONGFORM]);
        $filter->setAuthors([$npub]);
        $filter->setSince(strtotime('-6 months')); // too much?
        $requestMessage = new RequestMessage($subscriptionId, [$filter]);

        // if user is logged in, use their settings
        /* @var  $user */
        $user = $this->tokenStorage->getToken()?->getUser();
        $relays = $this->defaultRelaySet;
        if ($user && $user->getRelays()) {
            $relays = new RelaySet();
            foreach ($user->getRelays() as $relayArr) {
                if ($relayArr[2] == 'write') {
                    $relays->addRelay(new Relay($relayArr[1]));
                }
            }
        }

        $request = new Request($relays, $requestMessage);

        $response = $request->send();
        // response is an n-dimensional array, where n is the number of relays in the set
        // check that response has events in the results
        foreach ($response as $relayRes) {
            $filtered = array_filter($relayRes, function ($item) {
                return $item->type === 'EVENT';
            });
            if (count($filtered) > 0) {
                $this->saveLongFormContent($filtered);
            }
        }
        // TODO handle relays that require auth
    }

    public function publishEvent(Event $event, array $relays): array
    {
        $eventMessage = new EventMessage($event);
        $relaySet = new RelaySet();
        foreach ($relays as $relayWss) {
            $relay = new Relay($relayWss);
            $relaySet->addRelay($relay);
        }
        $relaySet->setMessage($eventMessage);
        // TODO handle responses appropriately
        return $relaySet->send();
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

        $request = new Request($this->defaultRelaySet, $requestMessage);

        $response = $request->send();
        // response is an n-dimensional array, where n is the number of relays in the set
        // check that response has events in the results
        foreach ($response as $relayRes) {
            $filtered = array_filter($relayRes, function ($item) {
                return $item->type === 'EVENT';
            });
            if (count($filtered) > 0) {
                $this->saveLongFormContent($filtered);
            }
        }
    }

    /**
     * @throws \Exception
     */
    public function getLongFormFromNaddr($slug, $relayList, $author, $kind): void
    {
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

    /**
     * Fetch a note by its ID
     *
     * @param string $noteId The note ID
     * @return object|null The note or null if not found
     * @throws \Exception
     */
    public function getNoteById(string $noteId): ?object
    {
        return $this->getEventById($noteId);
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
        // Get relays
        $request = $this->createNostrRequest(
            kinds: [KindsEnum::RELAY_LIST],
            filters: ['authors' => [$npub]],
            relaySet: $this->defaultRelaySet
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
        return array_filter(array_unique($relays), function ($relay) {
            return str_starts_with($relay, 'wss:') && !str_contains($relay, 'localhost');
        });
    }

    /**
     * Get comments for a specific coordinate
     *
     * @param string $coordinate The event coordinate (kind:pubkey:identifier)
     * @return array Array of comment events
     * @throws \Exception
     */
    public function getComments(string $coordinate): array
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
        // Get relays for the author
        $authorRelays = $this->getTopReputableRelaysForAuthor($pubkey);
        // Turn into a relaySet
        $relaySet = $this->createRelaySet($authorRelays);

        // Create request using the helper method
        $request = $this->createNostrRequest(
            kinds: [KindsEnum::COMMENTS->value, KindsEnum::ZAP_RECEIPT->value],
            filters: ['tag' => ['#a', [$coordinate]]],
            relaySet: $relaySet
        );

        // Process the response and deduplicate by eventId
        $uniqueEvents = [];
        $this->processResponse($request->send(), function($event) use (&$uniqueEvents, $pubkey) {
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

        // Get author's relays for better chances of finding zaps
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
    public function getLongFormContentForPubkey(string $ident): array
    {
        // Add user relays to the default set
        $authorRelays = $this->getTopReputableRelaysForAuthor($ident);
        // Create a RelaySet from the author's relays
        $relaySet = $this->defaultRelaySet;
        if (!empty($authorRelays)) {
            $relaySet = $this->createRelaySet($authorRelays);
        }

        // Create request using the helper method
        $request = $this->createNostrRequest(
            kinds: [KindsEnum::LONGFORM],
            filters: [
                'authors' => [$ident],
                'limit' => 10
            ],
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

    private function createNostrRequest(array $kinds, array $filters = [], ?RelaySet $relaySet = null): Request
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

        $requestMessage = new RequestMessage($subscription->getId(), [$filter]);
        return new Request($relaySet ?? $this->defaultRelaySet, $requestMessage);
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
                        $this->logger->warning('Invalid response item', [
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
                            $this->logger->warning('Relay requires authentication', [
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
}
