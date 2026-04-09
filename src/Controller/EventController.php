<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event as EventEntity;
use App\Enum\KindsEnum;
use App\Message\FetchEventFromRelaysMessage;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrLinkParser;
use Exception;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class EventController extends AbstractController
{
    public function __construct(
        private readonly \App\Service\ArticleEventProjector $articleEventProjector,
    ) {}

    /**
     * Convert Event entity to stdClass object compatible with NostrClient responses
     */
    private function entityToObject(EventEntity $entity): \stdClass
    {
        $obj = new \stdClass();
        $obj->id = $entity->getId();
        $obj->kind = $entity->getKind();
        $obj->pubkey = $entity->getPubkey();
        $obj->content = $entity->getContent();
        $obj->created_at = $entity->getCreatedAt();
        $obj->tags = $entity->getTags();
        $obj->sig = $entity->getSig();
        return $obj;
    }

    /**
     * @throws Exception
     */
    #[Route('/e/{nevent}', name: 'nevent', requirements: ['nevent' => '^(nevent|note|naddr|nprofile)1.*'])]
    public function index($nevent, \Symfony\Component\HttpFoundation\Request $request,
                          RedisCacheService $redisCacheService, NostrLinkParser $nostrLinkParser,
                          LoggerInterface $logger, EventRepository $eventRepository,
                          MessageBusInterface $messageBus, NostrClient $nostrClient,
                          GenericEventProjector $genericEventProjector,
                          \App\Service\Nostr\UserRelayListService $userRelayListService): Response
    {
        $logger->info('Accessing event page', ['nevent' => $nevent]);

        try {
            // Decode nevent - nevent1... is a NIP-19 encoded event identifier
            $decoded = new Bech32($nevent);
            $logger->info('Decoded event', ['decoded' => json_encode($decoded)]);

            // Get the event using the event ID
            /** @var Data $data */
            $data = $decoded->data;
            $logger->info('Event data', ['data' => json_encode($data)]);

            // Sort which event type this is using $data->type
            switch ($decoded->type) {
                case 'note':
                    // Handle note (regular event) - check DB first
                    $eventId = $data->data;
                    $logger->info('Looking up note in database', ['eventId' => $eventId]);

                    $dbEvent = $eventRepository->findById($eventId);
                    if ($dbEvent) {
                        $logger->info('Event found in database', ['eventId' => $eventId]);
                        $event = $this->entityToObject($dbEvent);
                    } else {
                        // Synchronous fetch — notes are targeted limit-1 lookups,
                        // no need to push the user to an async loading page.
                        $logger->info('Note not in database, trying synchronous relay fetch', ['eventId' => $eventId]);
                        try {
                            $rawEvent = $nostrClient->getEventById($eventId);
                            if ($rawEvent !== null) {
                                $persisted = $genericEventProjector->projectEventFromNostrEvent(
                                    $rawEvent,
                                    'sync-note-fetch',
                                );
                                $logger->info('Note found on relays and persisted', ['eventId' => $persisted->getId()]);
                                $event = $this->entityToObject($persisted);
                                break;
                            }
                        } catch (\Throwable $e) {
                            $logger->warning('Synchronous relay fetch failed for note', [
                                'eventId' => $eventId,
                                'error' => $e->getMessage(),
                            ]);
                        }

                        // Sync fetch didn't find it — fall back to async broader search
                        $lookupKey = 'note:' . $eventId;
                        $logger->info('Note not found synchronously, dispatching async relay search', ['eventId' => $eventId]);
                        $messageBus->dispatch(new FetchEventFromRelaysMessage(
                            lookupKey: $lookupKey,
                            type: 'note',
                            eventId: $eventId,
                        ));
                        return $this->render('event/loading.html.twig', [
                            'nevent' => $nevent,
                            'lookupKey' => $lookupKey,
                            'hasRelayHints' => false,
                        ]);
                    }
                    break;

                case 'nprofile':
                    // Redirect to author profile if it's a profile identifier
                    $logger->info('Redirecting to author profile', ['pubkey' => $data->pubkey]);
                    return $this->redirectToRoute('author-redirect', ['pubkey' => $data->pubkey]);

                case 'nevent':
                    // Handle nevent identifier (event with additional metadata) - check DB first
                    $eventId = $data->id;
                    $relays = $data->relays ?? [];
                    $authorPubkey = $data->author ?? null;
                    $logger->info('Looking up nevent in database', [
                        'eventId' => $eventId,
                        'authorPubkey' => $authorPubkey,
                        'hintRelays' => $relays,
                    ]);

                    $dbEvent = $eventRepository->findById($eventId);
                    if ($dbEvent) {
                        $logger->info('Event found in database', ['eventId' => $eventId]);
                        $event = $this->entityToObject($dbEvent);
                    } else {
                        // Enrich relay list with author's relay list when we know the author.
                        // This is critical for kind 1 (notes) which are typically only on
                        // the author's personal relays, not on content/article relays.
                        if ($authorPubkey) {
                            try {
                                $authorRelays = $userRelayListService->getRelaysForFetching($authorPubkey);
                                $logger->info('Resolved author relay list for nevent lookup', [
                                    'authorPubkey' => $authorPubkey,
                                    'authorRelays' => $authorRelays,
                                ]);
                                // Merge: hint relays first, then author relays (dedup)
                                foreach ($authorRelays as $ar) {
                                    if (!in_array($ar, $relays, true)) {
                                        $relays[] = $ar;
                                    }
                                }
                            } catch (\Throwable $e) {
                                $logger->warning('Failed to resolve author relay list', [
                                    'authorPubkey' => $authorPubkey,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }

                        // Synchronous fetch — targeted limit-1 lookup, the user
                        // is explicitly looking for this event so a brief wait is fine.
                        $logger->info('nevent not in database, querying relays synchronously', [
                            'eventId' => $eventId,
                            'relays' => $relays,
                        ]);
                        try {
                            $rawEvent = $nostrClient->getEventById($eventId, $relays);
                            if ($rawEvent !== null) {
                                $persisted = $genericEventProjector->projectEventFromNostrEvent(
                                    $rawEvent,
                                    $relays[0] ?? 'sync-nevent-fetch',
                                );
                                $logger->info('Event found on relays and persisted', ['eventId' => $persisted->getId()]);
                                $event = $this->entityToObject($persisted);
                                break;
                            }
                        } catch (\Throwable $e) {
                            $logger->warning('Synchronous relay fetch failed for nevent', [
                                'eventId' => $eventId,
                                'error' => $e->getMessage(),
                            ]);
                        }

                        // Sync fetch didn't find it — fall back to async broader search
                        $lookupKey = 'nevent:' . $eventId;
                        $logger->info('nevent not found synchronously, dispatching async relay search', ['eventId' => $eventId]);
                        $messageBus->dispatch(new FetchEventFromRelaysMessage(
                            lookupKey: $lookupKey,
                            type: 'nevent',
                            eventId: $eventId,
                            pubkey: $authorPubkey,
                            relays: $relays,
                        ));
                        return $this->render('event/loading.html.twig', [
                            'nevent' => $nevent,
                            'lookupKey' => $lookupKey,
                            'hasRelayHints' => !empty($data->relays),
                        ]);
                    }
                    break;

                case 'naddr':
                    // Handle naddr (parameterized replaceable event) - check DB first
                    $relays = $data->relays ?? [];
                    $logger->info('Looking up naddr in database', [
                        'kind' => $data->kind,
                        'pubkey' => $data->pubkey,
                        'identifier' => $data->identifier,
                    ]);

                    // Fast path for articles: check Article table first and redirect
                    // directly. This is the canonical path for /article/naddr1... which
                    // now redirects here, and avoids falling through to the generic
                    // event renderer when a proper article view is available.
                    if ($data->kind === KindsEnum::LONGFORM->value || $data->kind === KindsEnum::LONGFORM_DRAFT->value) {
                        $articleEntity = $eventRepository->getEntityManager()
                            ->getRepository(\App\Entity\Article::class)
                            ->findOneBy(['slug' => $data->identifier, 'pubkey' => $data->pubkey]);
                        if ($articleEntity) {
                            $npub = \App\Util\NostrKeyUtil::hexToNpub($data->pubkey);
                            return $this->redirectToRoute('author-article-slug', [
                                'npub' => $npub,
                                'slug' => $data->identifier,
                            ]);
                        }
                    }

                    $dbEvent = $eventRepository->findByNaddr($data->kind, $data->pubkey, $data->identifier);
                    if ($dbEvent) {
                        $logger->info('Event found in database', [
                            'eventId' => $dbEvent->getId(),
                            'kind' => $data->kind,
                        ]);
                        $event = $this->entityToObject($dbEvent);

                        // For article kinds, ensure the Article projection exists.
                        // The Event may have been ingested via GenericEventProjector
                        // without a corresponding Article entity — recover here.
                        if ($data->kind === KindsEnum::LONGFORM->value || $data->kind === KindsEnum::LONGFORM_DRAFT->value) {
                            try {
                                $this->articleEventProjector->projectArticleFromEvent(
                                    $event,
                                    'db-naddr-recovery',
                                );
                            } catch (\Throwable $e) {
                                $logger->warning('Article projection recovery failed for naddr DB hit', [
                                    'kind' => $data->kind,
                                    'pubkey' => $data->pubkey,
                                    'identifier' => $data->identifier,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                            // Re-check the Article table — redirect if projection succeeded
                            $articleEntity = $eventRepository->getEntityManager()
                                ->getRepository(\App\Entity\Article::class)
                                ->findOneBy(['slug' => $data->identifier, 'pubkey' => $data->pubkey]);
                            if ($articleEntity) {
                                $npub = \App\Util\NostrKeyUtil::hexToNpub($data->pubkey);
                                return $this->redirectToRoute('author-article-slug', [
                                    'npub' => $npub,
                                    'slug' => $data->identifier,
                                ]);
                            }
                        }
                    } else {
                        // Synchronous fetch — targeted limit-1 lookup, the user
                        // is explicitly looking for this event so a brief wait is fine.
                        // getEventByNaddr already prioritises hint relays, then falls
                        // back to author relays + default relays.
                        $logger->info('naddr not in database, querying relays synchronously', [
                            'kind' => $data->kind,
                            'pubkey' => $data->pubkey,
                            'identifier' => $data->identifier,
                            'relays' => $relays,
                        ]);
                        try {
                            $rawEvent = $nostrClient->getEventByNaddr([
                                'kind' => $data->kind,
                                'pubkey' => $data->pubkey,
                                'identifier' => $data->identifier,
                                'relays' => $relays,
                            ]);
                            if ($rawEvent !== null) {
                                $relaySource = $relays[0] ?? 'sync-naddr-fetch';
                                $persisted = $genericEventProjector->projectEventFromNostrEvent(
                                    $rawEvent,
                                    $relaySource,
                                );
                                $rawKind = (int) ($rawEvent->kind ?? 0);
                                if ($rawKind === KindsEnum::LONGFORM->value || $rawKind === KindsEnum::LONGFORM_DRAFT->value) {
                                    try {
                                        $this->articleEventProjector->projectArticleFromEvent(
                                            $rawEvent,
                                            $relaySource,
                                        );
                                    } catch (\Throwable $e) {
                                        $logger->warning('Article projection failed during naddr sync fetch', [
                                            'error' => $e->getMessage(),
                                        ]);
                                    }
                                }
                                $logger->info('Event found on relays and persisted', [
                                    'eventId' => $persisted->getId(),
                                    'kind' => $data->kind,
                                ]);
                                $event = $this->entityToObject($persisted);
                                break;
                            }
                        } catch (\Throwable $e) {
                            $logger->warning('Synchronous relay fetch failed for naddr', [
                                'kind' => $data->kind,
                                'pubkey' => $data->pubkey,
                                'identifier' => $data->identifier,
                                'error' => $e->getMessage(),
                            ]);
                        }

                        // Sync fetch didn't find it — fall back to async as last resort
                        $lookupKey = sprintf('naddr:%d:%s:%s', $data->kind, $data->pubkey, $data->identifier);
                        $logger->info('naddr not found synchronously, dispatching async relay search', [
                            'kind' => $data->kind,
                            'pubkey' => $data->pubkey,
                            'identifier' => $data->identifier,
                        ]);
                        $messageBus->dispatch(new FetchEventFromRelaysMessage(
                            lookupKey: $lookupKey,
                            type: 'naddr',
                            kind: $data->kind,
                            pubkey: $data->pubkey,
                            identifier: $data->identifier,
                            relays: $relays,
                        ));
                        return $this->render('event/loading.html.twig', [
                            'nevent' => $nevent,
                            'lookupKey' => $lookupKey,
                            'hasRelayHints' => !empty($relays),
                        ]);
                    }

                    if ($data->kind === KindsEnum::LONGFORM->value || $data->kind === KindsEnum::LONGFORM_DRAFT->value) {
                        // Only redirect to the article page if the Article entity
                        // was actually projected.  The sync fetch persists the Event
                        // but article projection can fail silently — in that case
                        // fall through to the generic event renderer.
                        $articleEntity = $eventRepository->getEntityManager()
                            ->getRepository(\App\Entity\Article::class)
                            ->findOneBy(['slug' => $data->identifier, 'pubkey' => $data->pubkey]);
                        if ($articleEntity) {
                            $logger->info('Redirecting to article', ['identifier' => $data->identifier]);
                            $npub = \App\Util\NostrKeyUtil::hexToNpub($data->pubkey);
                            return $this->redirectToRoute('author-article-slug', [
                                'npub' => $npub,
                                'slug' => $data->identifier,
                            ]);
                        }
                        $logger->warning('Event fetched but Article entity not found, rendering generic event page', [
                            'kind' => $data->kind,
                            'pubkey' => $data->pubkey,
                            'identifier' => $data->identifier,
                        ]);
                    }

                    // Redirect curation sets to their dedicated views
                    $curationKinds = [
                        KindsEnum::CURATION_SET->value,       // 30004
                        KindsEnum::CURATION_VIDEOS->value,    // 30005
                        KindsEnum::CURATION_PICTURES->value,  // 30006
                    ];
                    if (in_array($data->kind, $curationKinds, true)) {
                        $npub = \App\Util\NostrKeyUtil::hexToNpub($data->pubkey);
                        $logger->info('Redirecting to curation set', [
                            'kind' => $data->kind,
                            'npub' => $npub,
                            'slug' => $data->identifier,
                        ]);
                        return $this->redirectToRoute('curation-set', [
                            'npub' => $npub,
                            'kind' => $data->kind,
                            'slug' => $data->identifier,
                        ]);
                    }
                    break;

                default:
                    $logger->error('Unsupported event type', ['type' => $decoded->type]);
                    throw new NotFoundHttpException('Unsupported event type: ' . $decoded->type);
            }

            if (!$event) {
                $logger->warning('Event not found', ['data' => $data]);
                throw new NotFoundHttpException('Event not found');
            }

            // Parse event content for Nostr links
            $nostrLinks = [];
            if (isset($event->content)) {
                $nostrLinks = $nostrLinkParser->parseLinks($event->content);
                $logger->info('Parsed Nostr links from content', ['count' => count($nostrLinks)]);
            }

            $authorMetadata = $redisCacheService->getMetadata($event->pubkey);

            // Batch fetch profiles for follow pack events (kind 39089)
            $followPackProfiles = [];
            if (isset($event->kind) && $event->kind == 39089 && isset($event->tags)) {
                $pubkeys = [];
                foreach ($event->tags as $tag) {
                    if (is_array($tag) && $tag[0] === 'p' && isset($tag[1])) {
                        $pubkeys[] = $tag[1];
                    }
                }
                if (!empty($pubkeys)) {
                    $logger->info('Batch fetching follow pack profiles', ['count' => count($pubkeys)]);
                    $metadataMap = $redisCacheService->getMultipleMetadata($pubkeys);
                    // Convert UserMetadata DTOs to stdClass for template compatibility
                    $followPackProfiles = array_map(fn($metadata) => $metadata->toStdClass(), $metadataMap);
                }
            }

            // Render template with the event data and extracted Nostr links
            $response = $this->render('event/index.html.twig', [
                'event' => $event,
                'author' => $authorMetadata,
                'nostrLinks' => $nostrLinks,
                'followPackProfiles' => $followPackProfiles
            ]);

            // Add HTTP caching headers for request-level caching
            $response->setPublic(); // Allow public caching (browsers, CDNs)
            $response->setMaxAge(300); // Cache for 5 minutes
            $response->setSharedMaxAge(300); // Same for shared caches (CDNs)

            // Add ETag for conditional requests
            $etag = md5($nevent . ($event->created_at ?? '') . ($event->content ?? ''));
            $response->setEtag($etag);
            $response->setLastModified(new \DateTime('@' . ($event->created_at ?? time())));

            // Check if client has current version
            $response->isNotModified($request);

            return $response;

        } catch (Exception $e) {
            $logger->error('Error processing event', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
