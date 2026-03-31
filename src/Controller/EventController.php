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
                        // No relay hints for notes — dispatch async fetch
                        $lookupKey = 'note:' . $eventId;
                        $logger->info('Note not in database, dispatching async relay search', ['eventId' => $eventId]);
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
                                $authorRelays = $userRelayListService->getRelaysForFetching($authorPubkey, 4);
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

                        // Try synchronous fetch (hint relays + author relays)
                        if (!empty($relays)) {
                            $logger->info('nevent not in database, querying relays synchronously', [
                                'eventId' => $eventId,
                                'relays' => $relays,
                            ]);
                            try {
                                $rawEvent = $nostrClient->getEventById($eventId, $relays);
                                if ($rawEvent !== null) {
                                    $persisted = $genericEventProjector->projectEventFromNostrEvent(
                                        $rawEvent,
                                        $relays[0] ?? 'sync-hint-fetch',
                                    );
                                    $logger->info('Event found on relays and persisted', ['eventId' => $persisted->getId()]);
                                    $event = $this->entityToObject($persisted);
                                    break;
                                }
                            } catch (\Throwable $e) {
                                $logger->warning('Relay fetch failed for nevent, falling back to async', [
                                    'eventId' => $eventId,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }

                        // Relays didn't have it (or none available) — dispatch async broader search
                        $lookupKey = 'nevent:' . $eventId;
                        $logger->info('Dispatching async relay search for nevent', ['eventId' => $eventId]);
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
                    } else {
                        // If relay hints exist, query them synchronously (high hit rate expected)
                        if (!empty($relays)) {
                            $logger->info('naddr not in database, querying hint relays synchronously', [
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
                                ], hintOnly: true);
                                if ($rawEvent !== null) {
                                    $persisted = $genericEventProjector->projectEventFromNostrEvent(
                                        $rawEvent,
                                        $relays[0] ?? 'sync-hint-fetch',
                                    );
                                    // Also project Article entity for long-form content
                                    // so article routes work after redirect
                                    $rawKind = (int) ($rawEvent->kind ?? 0);
                                    if ($rawKind === KindsEnum::LONGFORM->value || $rawKind === KindsEnum::LONGFORM_DRAFT->value) {
                                        try {
                                            $this->articleEventProjector->projectArticleFromEvent(
                                                $rawEvent,
                                                $relays[0] ?? 'sync-hint-fetch',
                                            );
                                        } catch (\Throwable $e) {
                                            $logger->warning('Article projection failed during naddr sync fetch', [
                                                'error' => $e->getMessage(),
                                            ]);
                                        }
                                    }
                                    $logger->info('Event found on hint relays and persisted', [
                                        'eventId' => $persisted->getId(),
                                        'kind' => $data->kind,
                                    ]);
                                    $event = $this->entityToObject($persisted);
                                    break;
                                }
                            } catch (\Throwable $e) {
                                $logger->warning('Hint relay fetch failed for naddr, falling back to async', [
                                    'kind' => $data->kind,
                                    'pubkey' => $data->pubkey,
                                    'identifier' => $data->identifier,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }

                        // No relay hints, or hint relays didn't have it — dispatch async broader search
                        $lookupKey = sprintf('naddr:%d:%s:%s', $data->kind, $data->pubkey, $data->identifier);
                        $logger->info('Dispatching async relay search for naddr', [
                            'kind' => $data->kind,
                            'pubkey' => $data->pubkey,
                            'identifier' => $data->identifier,
                            'hasRelayHints' => !empty($relays),
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

                    if ($data->kind === KindsEnum::LONGFORM->value) {
                        // Redirect to the article page with the author's npub for a direct match
                        $logger->info('Redirecting to article', ['identifier' => $data->identifier]);
                        $npub = \App\Util\NostrKeyUtil::hexToNpub($data->pubkey);
                        return $this->redirectToRoute('author-article-slug', [
                            'npub' => $npub,
                            'slug' => $data->identifier,
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
