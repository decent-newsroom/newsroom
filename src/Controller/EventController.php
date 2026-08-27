<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Event as EventEntity;
use App\Enum\KindsEnum;
use App\Message\FetchEventFromRelaysMessage;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\GenericEventProjector;
use App\Service\Nostr\EventLookupKey;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrLinkParser;
use App\Util\Nip10TagParser;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

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
     * Resolve root OP for kind-1 notes via NIP-10 marked root e-tag.
     */
    private function resolveRootOpEvent(
        object $event,
        EventRepository $eventRepository,
        NostrClient $nostrClient,
        GenericEventProjector $genericEventProjector,
        LoggerInterface $logger,
    ): ?\stdClass {
        if ((int) ($event->kind ?? 0) !== KindsEnum::TEXT_NOTE->value) {
            return null;
        }

        if (!isset($event->tags) || !is_array($event->tags)) {
            return null;
        }

        $rootReference = Nip10TagParser::findRootReference($event->tags);
        if ($rootReference === null) {
            return null;
        }

        $rootEventId = $rootReference['eventId'];
        if ($rootEventId === ($event->id ?? '')) {
            return null;
        }

        $rootEntity = $eventRepository->findById($rootEventId);
        if ($rootEntity) {
            return $this->entityToObject($rootEntity);
        }

        try {
            $rawEvent = $nostrClient->getEventById($rootEventId, $rootReference['relays']);
            if ($rawEvent !== null) {
                $persisted = $genericEventProjector->projectEventFromNostrEvent(
                    $rawEvent,
                    $rootReference['relays'][0] ?? 'sync-root-fetch',
                );

                return $this->entityToObject($persisted);
            }
        } catch (\Throwable $e) {
            $logger->warning('Synchronous relay fetch failed for root OP event', [
                'eventId' => $rootEventId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function classifyPublicationTarget(array $tags): string
    {
        $hasArticleReference = false;

        foreach ($tags as $tag) {
            if (!is_array($tag) || ($tag[0] ?? null) !== 'a' || !isset($tag[1]) || !is_string($tag[1])) {
                continue;
            }

            $parts = explode(':', $tag[1], 2);
            $kind = isset($parts[0]) ? (int)$parts[0] : 0;

            if ($kind === KindsEnum::PUBLICATION_INDEX->value) {
                return 'magazine';
            }

            if (
                $kind === KindsEnum::LONGFORM->value
                || $kind === KindsEnum::LONGFORM_DRAFT->value
                || $kind === KindsEnum::PUBLICATION_CONTENT->value
                || $kind === KindsEnum::WIKI->value
            ) {
                $hasArticleReference = true;
            }
        }

        return $hasArticleReference ? 'reading_list' : 'unknown';
    }

    private function findDTag(array $tags): ?string
    {
        foreach ($tags as $tag) {
            if (!is_array($tag) || ($tag[0] ?? null) !== 'd' || !isset($tag[1]) || !is_string($tag[1])) {
                continue;
            }

            $slug = trim($tag[1]);
            if ($slug !== '') {
                return $slug;
            }
        }

        return null;
    }

    private function redirectPublicationIndexIfNeeded(object $event, LoggerInterface $logger): ?Response
    {
        $kind = (int) ($event->kind ?? 0);
        $tags = $event->tags ?? null;

        if ($kind !== KindsEnum::PUBLICATION_INDEX->value || !is_array($tags)) {
            return null;
        }

        $slug = $this->findDTag($tags);
        if ($slug === null) {
            return null;
        }

        $classification = $this->classifyPublicationTarget($tags);
        if ($classification === 'magazine') {
            $logger->info('Redirecting publication index to magazine page', ['slug' => $slug]);

            return $this->redirectToRoute('magazine-index', ['mag' => $slug]);
        }

        if ($classification !== 'reading_list') {
            return null;
        }

        $pubkey = (string) ($event->pubkey ?? '');
        if ($pubkey === '') {
            return null;
        }

        try {
            $npub = (static function (string $pubkey): string { return PublicKey::fromHex(strtolower(trim($pubkey)))?->toBech32() ?? throw new \InvalidArgumentException('Not a valid hex pubkey'); })((string) ($pubkey));
        } catch (\Throwable $e) {
            $logger->warning('Failed to redirect publication index to reading list due to invalid pubkey', [
                'pubkey' => $pubkey,
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $logger->info('Redirecting publication index to reading list page', [
            'npub' => $npub,
            'slug' => $slug,
        ]);

        return $this->redirectToRoute('reading-list', [
            'npub' => $npub,
            'slug' => $slug,
        ]);
    }

    private function isArticleKind(int $kind): bool
    {
        return $kind === KindsEnum::LONGFORM->value || $kind === KindsEnum::LONGFORM_DRAFT->value;
    }

    private function npubFromPubkey(string $pubkey): string
    {
        return PublicKey::fromHex(strtolower(trim($pubkey)))?->toBech32()
            ?? throw new \InvalidArgumentException('Not a valid hex pubkey');
    }

    private function redirectArticleIfProjected(
        int $kind,
        string $pubkey,
        string $identifier,
        EventRepository $eventRepository,
        LoggerInterface $logger,
    ): ?Response {
        if (!$this->isArticleKind($kind)) {
            return null;
        }

        $articleEntity = $eventRepository->getEntityManager()
            ->getRepository(\App\Entity\Article::class)
            ->findOneBy(['slug' => $identifier, 'pubkey' => $pubkey]);

        if (!$articleEntity) {
            return null;
        }

        $logger->info('Redirecting to article', ['identifier' => $identifier]);

        return $this->redirectToRoute('author-article-slug', [
            'npub' => $this->npubFromPubkey($pubkey),
            'slug' => $identifier,
        ]);
    }

    private function redirectCurationIfNeeded(
        int $kind,
        string $pubkey,
        string $identifier,
        LoggerInterface $logger,
    ): ?Response {
        $curationKinds = [
            KindsEnum::CURATION_SET->value,
            KindsEnum::CURATION_VIDEOS->value,
            KindsEnum::CURATION_PICTURES->value,
        ];

        if (!in_array($kind, $curationKinds, true)) {
            return null;
        }

        $npub = $this->npubFromPubkey($pubkey);
        $logger->info('Redirecting to curation set', [
            'kind' => $kind,
            'npub' => $npub,
            'slug' => $identifier,
        ]);

        return $this->redirectToRoute('curation-set', [
            'npub' => $npub,
            'kind' => $kind,
            'slug' => $identifier,
        ]);
    }

    private function renderEventResponse(
        object $event,
        string $nevent,
        \Symfony\Component\HttpFoundation\Request $request,
        RedisCacheService $redisCacheService,
        NostrLinkParser $nostrLinkParser,
        LoggerInterface $logger,
        EventRepository $eventRepository,
        NostrClient $nostrClient,
        GenericEventProjector $genericEventProjector,
    ): Response {
        $publicationRedirect = $this->redirectPublicationIndexIfNeeded($event, $logger);
        if ($publicationRedirect instanceof Response) {
            return $publicationRedirect;
        }

        $nostrLinks = [];
        if (isset($event->content)) {
            $nostrLinks = $nostrLinkParser->parseLinks($event->content);
            $logger->info('Parsed Nostr links from content', ['count' => count($nostrLinks)]);
        }

        $authorMetadata = $redisCacheService->getMetadata($event->pubkey);

        $opEvent = $this->resolveRootOpEvent(
            $event,
            $eventRepository,
            $nostrClient,
            $genericEventProjector,
            $logger,
        );
        $opAuthorMetadata = $opEvent ? $redisCacheService->getMetadata($opEvent->pubkey) : null;

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
                $followPackProfiles = array_map(fn($metadata) => $metadata->toStdClass(), $metadataMap);
            }
        }

        $response = $this->render('event/index.html.twig', [
            'event' => $event,
            'author' => $authorMetadata,
            'opEvent' => $opEvent,
            'opAuthor' => $opAuthorMetadata,
            'nostrLinks' => $nostrLinks,
            'followPackProfiles' => $followPackProfiles,
        ]);

        $response->setPublic();
        $response->setMaxAge(300);
        $response->setSharedMaxAge(300);
        $response->setEtag(md5($nevent . ($event->created_at ?? '') . ($event->content ?? '')));
        $response->setLastModified(new \DateTime('@' . ($event->created_at ?? time())));
        $response->isNotModified($request);

        return $response;
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
                        } catch (\Throwable $e) {
                            $logger->warning('Synchronous relay fetch failed for note', [
                                'eventId' => $eventId,
                                'error' => $e->getMessage(),
                            ]);
                            $rawEvent = null;
                        }

                        if ($rawEvent !== null) {
                            try {
                                $persisted = $genericEventProjector->projectEventFromNostrEvent(
                                    $rawEvent,
                                    'sync-note-fetch',
                                );
                                $logger->info('Note found on relays and persisted', ['eventId' => $persisted->getId()]);
                                $event = $this->entityToObject($persisted);
                                break;
                            } catch (\Throwable $e) {
                                $logger->error('Synchronous note projection failed after relay hit', [
                                    'eventId' => $rawEvent->id ?? $eventId,
                                    'kind' => $rawEvent->kind ?? null,
                                    'error' => $e->getMessage(),
                                ]);
                                $event = $rawEvent;
                                break;
                            }
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
                            'lookupTopic' => EventLookupKey::topic($lookupKey),
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
                        // Enrich relay list with known author relays when we know the author,
                        // but do not perform blocking NIP-65 network discovery in the HTTP
                        // request. Relay hints should be tried immediately; the async fallback
                        // can do the broader network relay-list lookup if needed.
                        if ($authorPubkey) {
                            try {
                                $authorRelays = $userRelayListService->getRelaysForEventLookupCacheOrDb($authorPubkey);
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
                        } catch (\Throwable $e) {
                            $logger->warning('Synchronous relay fetch failed for nevent', [
                                'eventId' => $eventId,
                                'error' => $e->getMessage(),
                            ]);
                            $rawEvent = null;
                        }

                        if ($rawEvent !== null) {
                            try {
                                $persisted = $genericEventProjector->projectEventFromNostrEvent(
                                    $rawEvent,
                                    $relays[0] ?? 'sync-nevent-fetch',
                                );
                                $logger->info('Event found on relays and persisted', ['eventId' => $persisted->getId()]);
                                $event = $this->entityToObject($persisted);
                                break;
                            } catch (\Throwable $e) {
                                $logger->error('Synchronous nevent projection failed after relay hit', [
                                    'eventId' => $rawEvent->id ?? $eventId,
                                    'kind' => $rawEvent->kind ?? null,
                                    'error' => $e->getMessage(),
                                ]);
                                $event = $rawEvent;
                                break;
                            }
                        }

                        // Sync fetch didn't find it — fall back to async broader search
                        $lookupKey = EventLookupKey::forNevent($eventId);
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
                            'lookupTopic' => EventLookupKey::topic($lookupKey),
                            'hasRelayHints' => !empty($data->relays),
                        ]);
                    }
                    break;

                case 'naddr':
                    // Handle naddr (parameterized replaceable event) - check DB first
                    $naddrKind = (int) ($data->kind ?? 0);
                    $naddrPubkey = (string) ($data->pubkey ?? '');
                    $naddrIdentifier = trim((string) ($data->identifier ?? ''));
                    $relays = $data->relays ?? [];
                    $logger->info('Looking up naddr in database', [
                        'kind' => $naddrKind,
                        'pubkey' => $naddrPubkey,
                        'identifier' => $naddrIdentifier,
                    ]);

                    // Canonicalize long-form naddr links to the article route.
                    // The article controller already handles relay fetch fallback.
                    if ($naddrKind === KindsEnum::LONGFORM->value && $naddrPubkey !== '' && $naddrIdentifier !== '') {
                        try {
                            return $this->redirectToRoute('author-article-slug', [
                                'npub' => $this->npubFromPubkey($naddrPubkey),
                                'slug' => $naddrIdentifier,
                            ]);
                        } catch (\Throwable $e) {
                            $logger->warning('Failed to canonicalize long-form naddr to article route', [
                                'pubkey' => $naddrPubkey,
                                'identifier' => $naddrIdentifier,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    if ($naddrKind === KindsEnum::PUBLICATION_CONTENT->value && $naddrPubkey !== '' && $naddrIdentifier !== '') {
                        return $this->redirectToRoute('chapter', ['naddr' => $nevent]);
                    }

                    // Fast path for article drafts: check Article table first and redirect
                    // directly when a proper article projection already exists.
                    if ($this->isArticleKind($naddrKind)) {
                        $articleRedirect = $this->redirectArticleIfProjected(
                            $naddrKind,
                            $naddrPubkey,
                            $naddrIdentifier,
                            $eventRepository,
                            $logger,
                        );
                        if ($articleRedirect instanceof Response) {
                            return $articleRedirect;
                        }
                    }

                    $dbEvent = $eventRepository->findByNaddr($naddrKind, $naddrPubkey, $naddrIdentifier);
                    if ($dbEvent) {
                        $logger->info('Event found in database', [
                            'eventId' => $dbEvent->getId(),
                            'kind' => $naddrKind,
                        ]);
                        $event = $this->entityToObject($dbEvent);

                        // For article kinds, ensure the Article projection exists.
                        // The Event may have been ingested via GenericEventProjector
                        // without a corresponding Article entity — recover here.
                        if ($this->isArticleKind($naddrKind)) {
                            try {
                                $this->articleEventProjector->projectArticleFromEvent(
                                    $event,
                                    'db-naddr-recovery',
                                );
                            } catch (\Throwable $e) {
                                $logger->warning('Article projection recovery failed for naddr DB hit', [
                                    'kind' => $naddrKind,
                                    'pubkey' => $naddrPubkey,
                                    'identifier' => $naddrIdentifier,
                                    'error' => $e->getMessage(),
                                ]);
                            }

                            $articleRedirect = $this->redirectArticleIfProjected(
                                $naddrKind,
                                $naddrPubkey,
                                $naddrIdentifier,
                                $eventRepository,
                                $logger,
                            );
                            if ($articleRedirect instanceof Response) {
                                return $articleRedirect;
                            }

                            $logger->warning('Event found but Article entity not found, rendering generic event page', [
                                'kind' => $naddrKind,
                                'pubkey' => $naddrPubkey,
                                'identifier' => $naddrIdentifier,
                            ]);
                        }

                        $curationRedirect = $this->redirectCurationIfNeeded($naddrKind, $naddrPubkey, $naddrIdentifier, $logger);
                        if ($curationRedirect instanceof Response) {
                            return $curationRedirect;
                        }

                        return $this->renderEventResponse(
                            $event,
                            $nevent,
                            $request,
                            $redisCacheService,
                            $nostrLinkParser,
                            $logger,
                            $eventRepository,
                            $nostrClient,
                            $genericEventProjector,
                        );
                    }

                    // Synchronous fetch — targeted limit-1 lookup, the user
                    // is explicitly looking for this event so a brief wait is fine.
                    // getEventByNaddr prioritises hint relays plus cached/DB author relays;
                    // it must not perform blocking NIP-65 network discovery here.
                    $logger->info('naddr not in database, querying relays synchronously', [
                        'kind' => $naddrKind,
                        'pubkey' => $naddrPubkey,
                        'identifier' => $naddrIdentifier,
                        'relays' => $relays,
                    ]);
                    try {
                        $rawEvent = $nostrClient->getEventByNaddr([
                            'kind' => $naddrKind,
                            'pubkey' => $naddrPubkey,
                            'identifier' => $naddrIdentifier,
                            'relays' => $relays,
                        ], allowRelayListNetworkFetch: false);
                    } catch (\Throwable $e) {
                        $logger->warning('Synchronous relay fetch failed for naddr', [
                            'kind' => $naddrKind,
                            'pubkey' => $naddrPubkey,
                            'identifier' => $naddrIdentifier,
                            'error' => $e->getMessage(),
                        ]);
                        $rawEvent = null;
                    }

                    if ($rawEvent !== null) {
                        $relaySource = $relays[0] ?? 'sync-naddr-fetch';
                        try {
                            $persisted = $genericEventProjector->projectEventFromNostrEvent(
                                $rawEvent,
                                $relaySource,
                            );
                            $rawKind = (int) ($rawEvent->kind ?? 0);
                            if ($this->isArticleKind($rawKind)) {
                                try {
                                    $this->articleEventProjector->projectArticleFromEvent(
                                        $rawEvent,
                                        $relaySource,
                                    );
                                } catch (\Throwable $e) {
                                    $logger->warning('Article projection failed during naddr sync fetch', [
                                        'eventId' => $rawEvent->id ?? null,
                                        'kind' => $rawKind,
                                        'error' => $e->getMessage(),
                                    ]);
                                }
                            }
                            $logger->info('Event found on relays and persisted', [
                                'eventId' => $persisted->getId(),
                                'kind' => $naddrKind,
                            ]);
                            $event = $this->entityToObject($persisted);
                        } catch (\Throwable $e) {
                            $logger->error('Synchronous naddr projection failed after relay hit', [
                                'eventId' => $rawEvent->id ?? null,
                                'kind' => $rawEvent->kind ?? $naddrKind,
                                'pubkey' => $naddrPubkey,
                                'identifier' => $naddrIdentifier,
                                'error' => $e->getMessage(),
                            ]);
                            $event = $rawEvent;
                        }

                        $articleRedirect = $this->redirectArticleIfProjected(
                            (int) ($event->kind ?? $naddrKind),
                            $naddrPubkey,
                            $naddrIdentifier,
                            $eventRepository,
                            $logger,
                        );
                        if ($articleRedirect instanceof Response) {
                            return $articleRedirect;
                        }

                        $curationRedirect = $this->redirectCurationIfNeeded($naddrKind, $naddrPubkey, $naddrIdentifier, $logger);
                        if ($curationRedirect instanceof Response && isset($persisted)) {
                            return $curationRedirect;
                        }

                        return $this->renderEventResponse(
                            $event,
                            $nevent,
                            $request,
                            $redisCacheService,
                            $nostrLinkParser,
                            $logger,
                            $eventRepository,
                            $nostrClient,
                            $genericEventProjector,
                        );
                    }

                    // Sync fetch didn't find it — fall back to async as last resort
                    $lookupKey = EventLookupKey::forNaddr($naddrKind, $naddrPubkey, $naddrIdentifier);
                    $logger->info('naddr not found synchronously, dispatching async relay search', [
                        'kind' => $naddrKind,
                        'pubkey' => $naddrPubkey,
                        'identifier' => $naddrIdentifier,
                    ]);
                    $messageBus->dispatch(new FetchEventFromRelaysMessage(
                        lookupKey: $lookupKey,
                        type: 'naddr',
                        kind: $naddrKind,
                        pubkey: $naddrPubkey,
                        identifier: $naddrIdentifier,
                        relays: $relays,
                    ));
                    return $this->render('event/loading.html.twig', [
                        'nevent' => $nevent,
                        'lookupKey' => $lookupKey,
                        'lookupTopic' => EventLookupKey::topic($lookupKey),
                        'hasRelayHints' => !empty($relays),
                    ]);

                default:
                    $logger->error('Unsupported event type', ['type' => $decoded->type]);
                    throw new NotFoundHttpException('Unsupported event type: ' . $decoded->type);
            }

            if (!$event) {
                $logger->warning('Event not found', ['data' => $data]);
                throw new NotFoundHttpException('Event not found');
            }

            return $this->renderEventResponse(
                $event,
                $nevent,
                $request,
                $redisCacheService,
                $nostrLinkParser,
                $logger,
                $eventRepository,
                $nostrClient,
                $genericEventProjector,
            );

        } catch (Exception $e) {
            $logger->error('Error processing event', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
