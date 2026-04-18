<?php

declare(strict_types=1);

namespace App\Controller\Newsroom;

use App\Dto\UserMetadata;
use App\Enum\KindsEnum;
use App\ExpressionBundle\Service\ExpressionService;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\UserRelayListService;
use App\Util\NostrKeyUtil;
use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Pagerfanta;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Expression creator and browser — lets users build kind:30880 feed expression
 * events from predefined templates, view all published expressions, and
 * see the evaluated results of any expression.
 */
class ExpressionController extends AbstractController
{
    /**
     * Returns the predefined expression templates derived from NIP-EX examples.
     * Each template has a name, description, and pre-filled tags array.
     */
    private function getTemplates(): array
    {
        return [
            [
                'id' => 'recent-articles',
                'title' => 'Recent articles, newest first',
                'content' => 'Recent articles from the last 7 days, newest first, top 20.',
                'tags' => [
                    ['op', 'all'],
                    ['input', 'a', ''],
                    ['cmp', 'tag', 'published_at', 'gte', '7d'],
                    ['op', 'sort', 'tag', 'published_at', 'desc'],
                    ['op', 'slice', '0', '20'],
                ],
            ],
            [
                'id' => 'contacts-longform',
                'title' => 'Articles from my contacts',
                'content' => 'Longform items by my contacts, excluding my own.',
                'tags' => [
                    ['op', 'all'],
                    ['input', 'a', ''],
                    ['match', 'prop', 'pubkey', '$contacts'],
                    ['not', 'prop', 'pubkey', '$me'],
                    ['op', 'sort', 'tag', 'published_at', 'desc'],
                ],
            ],
            [
                'id' => 'my-interests',
                'title' => 'Articles matching my interests',
                'content' => 'Longform items tagged with any of my interests, newest first.',
                'tags' => [
                    ['op', 'all'],
                    ['input', 'a', ''],
                    ['match', 'tag', 't', '$interests'],
                    ['op', 'sort', 'tag', 'published_at', 'desc'],
                    ['op', 'slice', '0', '50'],
                ],
            ],
            [
                'id' => 'combine-sources',
                'title' => 'Combine two sources',
                'content' => 'Combine two sources and take the top 20 newest items.',
                'tags' => [
                    ['op', 'union'],
                    ['input', 'e', ''],
                    ['input', 'e', ''],
                    ['op', 'sort', 'tag', 'published_at', 'desc'],
                    ['op', 'slice', '0', '20'],
                ],
            ],
            [
                'id' => 'exclude-spam',
                'title' => 'Exclude spam tags',
                'content' => 'Start from another expression, then exclude spam-like tags.',
                'tags' => [
                    ['op', 'all'],
                    ['input', 'a', ''],
                    ['not', 'tag', 't', 'spam', 'promo', 'ads'],
                    ['op', 'sort', 'tag', 'published_at', 'desc'],
                ],
            ],
            [
                'id' => 'title-search',
                'title' => 'Title contains keyword',
                'content' => 'Keep items whose title contains a specific keyword.',
                'tags' => [
                    ['op', 'all'],
                    ['input', 'a', ''],
                    ['text', 'tag', 'title', 'contains-ci', 'nostr'],
                    ['op', 'sort', 'tag', 'published_at', 'desc'],
                ],
            ],
            [
                'id' => 'curated-longform',
                'title' => 'Curated longform + filter',
                'content' => 'Union of curated long-form list and latest from follows, keep only long-form, newest first, top 30.',
                'tags' => [
                    ['op', 'union'],
                    ['input', 'e', ''],
                    ['input', 'e', ''],
                    ['op', 'all'],
                    ['match', 'prop', 'kind', '30023'],
                    ['op', 'sort', 'tag', 'published_at', 'desc'],
                    ['op', 'slice', '0', '30'],
                ],
            ],
        ];
    }

    #[Route('/expressions', name: 'expression_list')]
    public function list(): Response
    {
        return $this->render('expressions/index.html.twig');
    }

    #[Route('/expressions/create', name: 'expression_create')]
    #[IsGranted('ROLE_USER')]
    public function create(): Response
    {
        return $this->render('expressions/create.html.twig', [
            'templates' => $this->getTemplates(),
            'existingEvent' => null,
        ]);
    }

    #[Route('/expressions/edit/{npub}/{dtag}', name: 'expression_edit', requirements: ['npub' => 'npub1[a-z0-9]+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(
        string $npub,
        string $dtag,
        EventRepository $eventRepository,
    ): Response {
        try {
            $pubkey = NostrKeyUtil::npubToHex($npub);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Invalid npub.');
        }

        $expression = $eventRepository->findByNaddr(
            KindsEnum::FEED_EXPRESSION->value,
            $pubkey,
            $dtag,
        );

        if (!$expression) {
            throw $this->createNotFoundException('Expression not found.');
        }

        // Only the author can edit their expression
        $user = $this->getUser();
        $userPubkey = null;
        if ($user) {
            $userIdentifier = $user->getUserIdentifier();
            try {
                $userPubkey = NostrKeyUtil::isNpub($userIdentifier)
                    ? NostrKeyUtil::npubToHex($userIdentifier)
                    : $userIdentifier;
            } catch (\Throwable) {}
        }

        if ($userPubkey !== $pubkey) {
            throw $this->createAccessDeniedException('You can only edit your own expressions.');
        }

        $existingEvent = [
            'kind' => $expression->getKind(),
            'content' => $expression->getContent(),
            'tags' => $expression->getTags(),
            'pubkey' => $expression->getPubkey(),
            'created_at' => $expression->getCreatedAt(),
        ];

        return $this->render('expressions/create.html.twig', [
            'templates' => $this->getTemplates(),
            'existingEvent' => $existingEvent,
        ]);
    }

    /**
     * Expression view page — evaluates a kind:30880 expression and shows
     * the resulting articles as cards, similar to follow-pack view.
     */
    #[Route('/expression/{npub}/{dtag}', name: 'expression_view', requirements: ['npub' => 'npub1[a-z0-9]+'])]
    public function view(
        string $npub,
        string $dtag,
        Request $request,
        EventRepository $eventRepository,
        ExpressionService $expressionService,
        RedisCacheService $redisCacheService,
    ): Response {
        try {
            $pubkey = NostrKeyUtil::npubToHex($npub);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Invalid npub.');
        }

        $expression = $eventRepository->findByNaddr(
            KindsEnum::FEED_EXPRESSION->value,
            $pubkey,
            $dtag,
        );

        if (!$expression) {
            throw $this->createNotFoundException('Expression not found.');
        }

        // Extract metadata
        $title = '';
        $description = null;
        foreach ($expression->getTags() as $tag) {
            $k = $tag[0] ?? '';
            match ($k) {
                'title' => $title = $tag[1] ?? '',
                'summary' => $description = $tag[1] ?? null,
                default => null,
            };
        }
        if (!$title) {
            $title = $dtag;
        }
        if (!$description && $expression->getContent()) {
            $description = $expression->getContent();
        }

        // Check if user is logged in — expression evaluation requires auth
        $user = $this->getUser();
        if (!$user) {
            return $this->render('expressions/view.html.twig', [
                'title' => $title,
                'description' => $description,
                'authorNpub' => $npub,
                'exprDtag' => $dtag,
                'articles' => [],
                'authorsMetadata' => [],
                'needsLogin' => true,
            ]);
        }

        // Evaluate the expression
        try {
            $userIdentifier = $user->getUserIdentifier();
            $userPubkey = NostrKeyUtil::isNpub($userIdentifier)
                ? NostrKeyUtil::npubToHex($userIdentifier)
                : $userIdentifier;

            $results = $expressionService->evaluateCached($expression, $userPubkey);

            // Extract Event entities from NormalizedItem results
            $allArticles = array_map(fn($item) => $item->getEvent(), $results);

            // Paginate
            $page = max(1, (int) $request->query->get('page', 1));
            $perPage = 20;
            $pager = new Pagerfanta(new ArrayAdapter($allArticles));
            $pager->setMaxPerPage($perPage);
            $pager->setCurrentPage(min($page, max(1, $pager->getNbPages())));

            $articles = array_slice($allArticles, ($pager->getCurrentPage() - 1) * $perPage, $perPage);

            // Author metadata for cards
            $articlePubkeys = array_unique(array_map(fn($a) => $a->getPubkey(), $articles));
            $articleMetaMap = $redisCacheService->getMultipleMetadata($articlePubkeys);
            $authorsMetadataStd = [];
            foreach ($articleMetaMap as $pk => $meta) {
                $authorsMetadataStd[$pk] = $meta instanceof UserMetadata
                    ? $meta->toStdClass() : $meta;
            }

            return $this->render('expressions/view.html.twig', [
                'title' => $title,
                'description' => $description,
                'authorNpub' => $npub,
                'exprDtag' => $dtag,
                'articles' => $articles,
                'authorsMetadata' => $authorsMetadataStd,
                'pager' => $pager,
            ]);
        } catch (\Throwable $e) {
            return $this->render('expressions/view.html.twig', [
                'title' => $title,
                'description' => $description,
                'authorNpub' => $npub,
                'exprDtag' => $dtag,
                'articles' => [],
                'authorsMetadata' => [],
                'error' => 'Could not evaluate expression: ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Receives a signed kind:30880 event, validates, persists, publishes to relays,
     * and returns the naddr for the feed API link.
     */
    #[Route('/api/expressions/publish', name: 'api_expression_publish', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function publish(
        Request $request,
        NostrClient $nostrClient,
        GenericEventProjector $genericEventProjector,
        UserRelayListService $userRelayListService,
        LoggerInterface $logger,
    ): JsonResponse {
        try {
            $data = json_decode($request->getContent(), true);
            if (!$data || !isset($data['event'])) {
                return new JsonResponse(['error' => 'Invalid request data'], 400);
            }

            $signedEvent = $data['event'];

            // Validate required fields
            if (!isset(
                $signedEvent['id'],
                $signedEvent['pubkey'],
                $signedEvent['created_at'],
                $signedEvent['kind'],
                $signedEvent['sig'],
            )) {
                return new JsonResponse(['error' => 'Missing required event fields'], 400);
            }

            // Only allow kind 30880 (feed expression)
            if ((int) $signedEvent['kind'] !== KindsEnum::FEED_EXPRESSION->value) {
                return new JsonResponse(['error' => 'Only kind 30880 (feed expression) events are accepted'], 400);
            }

            // Build a verifiable event object
            $eventObj = new Event();
            $eventObj->setId($signedEvent['id']);
            $eventObj->setPublicKey($signedEvent['pubkey']);
            $eventObj->setCreatedAt($signedEvent['created_at']);
            $eventObj->setKind($signedEvent['kind']);
            $eventObj->setTags($signedEvent['tags'] ?? []);
            $eventObj->setContent($signedEvent['content'] ?? '');
            $eventObj->setSignature($signedEvent['sig']);

            // Verify the event signature
            if (!$eventObj->verify()) {
                return new JsonResponse(['error' => 'Event signature verification failed'], 400);
            }

            $logger->info('Received expression event (kind 30880)', [
                'event_id' => $signedEvent['id'],
                'pubkey' => $signedEvent['pubkey'],
            ]);

            // Extract d-tag for naddr construction
            $dTag = '';
            foreach ($signedEvent['tags'] ?? [] as $tag) {
                if (($tag[0] ?? '') === 'd' && isset($tag[1])) {
                    $dTag = $tag[1];
                    break;
                }
            }

            // Persist locally via GenericEventProjector
            $genericEventProjector->projectEventFromNostrEvent(
                (object) $signedEvent,
                'expression-creator',
            );

            // Publish to user's relays
            $pubkey = $signedEvent['pubkey'];
            $relays = $userRelayListService->getRelaysForPublishing($pubkey);
            $relayResults = $nostrClient->publishEvent($eventObj, $relays);

            $successCount = 0;
            foreach ($relayResults as $result) {
                if ($result === true || (is_object($result) && isset($result->type) && $result->type === 'OK')) {
                    $successCount++;
                }
            }

            return new JsonResponse([
                'success' => true,
                'relays_success' => $successCount,
                'pubkey' => $pubkey,
                'd_tag' => $dTag,
            ]);
        } catch (\Throwable $e) {
            $logger->error('Expression publish error', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Publishing failed: ' . $e->getMessage()], 500);
        }
    }
}

