<?php

declare(strict_types=1);

namespace App\Controller\Editor;

use App\Entity\Article;
use App\Entity\User;
use App\Enum\KindsEnum;
use App\Enum\RolesEnum;
use App\Factory\ArticleFactory;
use App\Form\EditorType;
use App\Repository\UserEntityRepository;
use App\Service\AuthorRelayService;
use App\Service\Cache\RedisViewStore;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrEventParser;
use App\Util\NostrKeyUtil;
use App\Message\UpdateProfileProjectionMessage;
use App\Message\RevalidateProfileCacheMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class EditorController extends AbstractController
{
    /**
     * Create new article
     * @throws \Exception
     */
    #[Route('/article-editor/create', name: 'editor-create')]
    #[Route('/article-editor/edit/{slug}/draft', name: 'editor-edit-slug-draft')]
    #[Route('/article-editor/edit/{slug}', name: 'editor-edit-slug')]
    public function newArticle(
        Request $request,
        NostrClient $nostrClient,
        EntityManagerInterface $entityManager,
        NostrEventParser $eventParser,
        RedisViewStore $redisViewStore,
        AuthorRelayService $authorRelayService,
        MessageBusInterface $messageBus,
        $slug = null
    ): Response
    {
        $advancedMetadata = null;

        // Determine if this is a draft based on the route
        $routeName = $request->attributes->get('_route');
        $isDraft = str_contains($routeName, 'draft');
        $kind = $isDraft ? KindsEnum::LONGFORM_DRAFT : KindsEnum::LONGFORM;

        if (!$slug) {
            $article = new Article();
            $article->setKind($kind);
            $article->setCreatedAt(new \DateTimeImmutable());
            $formAction = $this->generateUrl($isDraft ? 'editor-create-draft' : 'editor-create');
        } else {
            $formAction = $this->generateUrl($isDraft ? 'editor-edit-slug-draft' : 'editor-edit-slug', ['slug' => $slug]);
            $repository = $entityManager->getRepository(Article::class);
            $slug = urldecode($slug);
            // Filter by kind to ensure we're loading the right type (draft vs published)
            $articles = $repository->findBy(['slug' => $slug, 'kind' => $kind]);
            if (count($articles) === 0) {
                throw $this->createNotFoundException('The article could not be found');
            }
            // Sort by createdAt, get latest revision
            usort($articles, function ($a, $b) {
                return $b->getCreatedAt() <=> $a->getCreatedAt();
            });
            $article = array_shift($articles);
            // Parse advanced metadata from the raw event if available
            if ($article->getRaw()) {
                $tags = $article->getRaw()['tags'] ?? [];
                $advancedMetadata = $eventParser->parseAdvancedMetadata($tags);
            }
        }

        $recentArticles = [];
        $drafts = [];

        $user = $this->getUser();
        if (!!$user) {
            $key = new Key();
            $currentPubkey = $key->convertToHex($user->getUserIdentifier());

            // Ensure user has relays - fetch if missing or empty
            $storedRelays = $user->getRelays();
            $hasValidRelays = !empty($storedRelays['write']) || !empty($storedRelays['all']);

            if (!$hasValidRelays || $storedRelays['all'] === AuthorRelayService::FALLBACK_RELAYS) {
                // Use non-blocking mode to avoid timeout - returns fallbacks immediately on cache miss
                $relays = $authorRelayService->getAuthorRelays($currentPubkey, true);
                if (!empty($relays['all'])) {
                    $user->setRelays($relays);
                    $entityManager->flush();
                }
                // Dispatch async message to fetch real relays in the background
                $messageBus->dispatch(new UpdateProfileProjectionMessage($currentPubkey));
            }

            $recentArticles = $entityManager->getRepository(Article::class)
                ->findBy(['pubkey' => $currentPubkey, 'kind' => KindsEnum::LONGFORM], ['createdAt' => 'DESC']);
            // Collapse by slug, keep only latest revision
            $recentArticles = array_reduce($recentArticles, function ($carry, $item) {
                if (!isset($carry[$item->getSlug()])) {
                    $carry[$item->getSlug()] = $item;
                }
                return $carry;
            });
            $recentArticles = array_values($recentArticles ?? []);
            // get drafts
            // look for drafts on relays first, grab latest 5 from there
            // one week ago
            $since = new \DateTime();
            $aWeekAgo = $since->sub(new \DateInterval('P1D'))->getTimestamp();
            $nostrClient->getLongFormContentForPubkey($currentPubkey, $aWeekAgo, KindsEnum::LONGFORM_DRAFT->value);
            $drafts = $entityManager->getRepository(Article::class)
                ->findBy(['pubkey' => $currentPubkey, 'kind' => KindsEnum::LONGFORM_DRAFT], ['createdAt' => 'DESC'], 5);
            // Collapse by slug, keep only latest revision
            $drafts = array_reduce($drafts, function ($carry, $item) {
                if (!isset($carry[$item->getSlug()])) {
                    $carry[$item->getSlug()] = $item;
                }
                return $carry;
            });
            $drafts = array_values($drafts ?? []);

            if ($article->getPubkey() === null) {
                $article->setPubkey($currentPubkey);
            }
        }

        $readingLists = [];
        if ($user) {
            $currentPubkey = $key->convertToHex($user->getUserIdentifier());
            $readingLists = $redisViewStore->buildAndCacheUserReadingLists($entityManager, $currentPubkey);
        }

        $form = $this->createForm(EditorType::class, $article, ['action' => $formAction]);
        // Populate advanced metadata form data
        if ($advancedMetadata) {
            $form->get('advancedMetadata')->setData($advancedMetadata);
        }

        $form->handleRequest($request);

        // load template with content editor
        return $this->render('editor/layout.html.twig', [
            'article' => $article,
            'form' => $form->createView(),
            'recentArticles' => $recentArticles,
            'drafts' => $drafts,
            'readingLists' => $readingLists,
        ]);
    }

    #[Route('/article-editor/preview/{npub}/{slug}', name: 'editor-preview-npub-slug')]
    public function previewArticle(
        $npub,
        $slug,
        EntityManagerInterface $entityManager,
        NostrEventParser $eventParser,
        RedisViewStore $redisViewStore,
        Request $request,
        NostrClient $nostrClient,
        AuthorRelayService $authorRelayService,
        MessageBusInterface $messageBus
    ): Response {
        // This route previews another user's article, but sidebar shows current user's lists for navigation.
        $advancedMetadata = null;

        $key = new Key();
        $pubkey = $key->convertToHex($npub);
        $slug = urldecode($slug);
        $repository = $entityManager->getRepository(Article::class);
        // Filter by kind to ensure we're loading the right type (draft vs published)
        $article = $repository->findOneBy(['slug' => $slug, 'pubkey' => $pubkey, 'kind' => KindsEnum::LONGFORM]);
        if (!$article) {
            throw $this->createNotFoundException('The article could not be found');
        }
        // Parse advanced metadata from the raw event if available
        if ($article->getRaw()) {
            $tags = $article->getRaw()['tags'] ?? [];
            $advancedMetadata = $eventParser->parseAdvancedMetadata($tags);
        }
        $formAction = $this->generateUrl('editor-preview-npub-slug', ['npub' => $npub, 'slug' => $slug]);
        $form = $this->createForm(EditorType::class, $article, ['action' => $formAction]);
        if ($advancedMetadata) {
            $form->get('advancedMetadata')->setData($advancedMetadata);
        }
        $form->handleRequest($request);

        // Load current user's recent articles, drafts, and reading lists for sidebar
        $recentArticles = [];
        $drafts = [];
        $readingLists = [];
        $user = $this->getUser();
        if ($user) {
            $currentPubkey = $key->convertToHex($user->getUserIdentifier());

            // Ensure user has relays - fetch if missing or empty
            $storedRelays = $user->getRelays();
            $hasValidRelays = !empty($storedRelays['write']) || !empty($storedRelays['all']);

            if (!$hasValidRelays) {
                // Use non-blocking mode to avoid timeout - returns fallbacks immediately
                $relays = $authorRelayService->getAuthorRelays($currentPubkey, false, true);
                if (!empty($relays['all'])) {
                    $user->setRelays($relays);
                    $entityManager->flush();
                }
                $messageBus->dispatch(new UpdateProfileProjectionMessage($currentPubkey));
            }

            $recentArticles = $entityManager->getRepository(Article::class)
                ->findBy(['pubkey' => $currentPubkey, 'kind' => KindsEnum::LONGFORM], ['createdAt' => 'DESC'], 5);
            // Collapse by slug, keep only latest revision
            $recentArticles = array_reduce($recentArticles, function ($carry, $item) {
                if (!isset($carry[$item->getSlug()])) {
                    $carry[$item->getSlug()] = $item;
                }
                return $carry;
            });
            $recentArticles = array_values($recentArticles ?? []);
            // get drafts
            $since = new \DateTime();
            $aWeekAgo = $since->sub(new \DateInterval('P1D'))->getTimestamp();
            $nostrClient->getLongFormContentForPubkey($currentPubkey, $aWeekAgo, KindsEnum::LONGFORM_DRAFT->value);
            $drafts = $entityManager->getRepository(Article::class)
                ->findBy(['pubkey' => $currentPubkey, 'kind' => KindsEnum::LONGFORM_DRAFT], ['createdAt' => 'DESC'], 5);
            // Collapse by slug, keep only latest revision
            $drafts = array_reduce($drafts, function ($carry, $item) {
                if (!isset($carry[$item->getSlug()])) {
                    $carry[$item->getSlug()] = $item;
                }
                return $carry;
            });
            $drafts = array_values($drafts ?? []);
            $readingLists = $redisViewStore->buildAndCacheUserReadingLists($entityManager, $currentPubkey);
        }

        return $this->render('editor/layout.html.twig', [
            'article' => $article,
            'form' => $form->createView(),
            'recentArticles' => $recentArticles,
            'drafts' => $drafts,
            'readingLists' => $readingLists,
        ]);
    }

    /**
     * API endpoint to receive and process signed Nostr events
     * @throws \Exception
     */
    #[Route('/api/article/publish', name: 'api-article-publish', methods: ['POST'])]
    public function publishNostrEvent(
        Request $request,
        EntityManagerInterface $entityManager,
        NostrClient $nostrClient,
        LoggerInterface $logger,
        NostrEventParser $eventParser,
        UserEntityRepository $userRepository,
        AuthorRelayService $authorRelayService,
        ArticleFactory $articleFactory,
        RedisViewStore $redisViewStore,
        MessageBusInterface $messageBus
    ): JsonResponse {
        // Increase execution time limit for relay publishing (60 seconds)
        set_time_limit(60);

        try {
            // Get JSON data
            $data = json_decode($request->getContent(), true);
            if (!$data || !isset($data['event'])) {
                return new JsonResponse(['error' => 'Invalid request data'], 400);
            }

            $signedEvent = $data['event'];
            // Convert the signed event array to a proper Event object
            $eventObj = Event::fromVerified((object)$signedEvent);

            if (!$eventObj->verify()) {
                return new JsonResponse(['error' => 'Event signature verification failed'], 400);
            }

            $formData = $data['formData'] ?? [];

            // Extract article data from the signed event
            // $articleData = $this->extractArticleDataFromEvent($signedEvent, $formData);

            // Determine if this is a draft based on the event kind
            $isDraft = ($signedEvent['kind'] === KindsEnum::LONGFORM_DRAFT->value);
            $kind = $isDraft ? KindsEnum::LONGFORM_DRAFT : KindsEnum::LONGFORM;

            // Create new article
            $article = $articleFactory->createFromLongFormContentEvent((object)$signedEvent);

            // Parse and store advanced metadata
            $advancedMetadata = $eventParser->parseAdvancedMetadata($signedEvent['tags']);
            $article->setAdvancedMetadata([
                'doNotRepublish' => $advancedMetadata->doNotRepublish,
                'license' => $advancedMetadata->getLicenseValue(),
                'zapSplits' => array_map(function($split) {
                    return [
                        'recipient' => $split->recipient,
                        'relay' => $split->relay,
                        'weight' => $split->weight,
                    ];
                }, $advancedMetadata->zapSplits),
                'contentWarning' => $advancedMetadata->contentWarning,
                'expirationTimestamp' => $advancedMetadata->expirationTimestamp,
                'isProtected' => $advancedMetadata->isProtected,
            ]);

            // Save to database
            $entityManager->persist($article);
            $entityManager->flush();

            // Grant ROLE_WRITER to the publishing user (only for published articles, not drafts)
            if (!$isDraft) {
                $key = new Key();
                $publisherNpub = $key->convertPublicKeyToBech32($signedEvent['pubkey']);
                $publisherUser = $userRepository->findOneBy(['npub' => $publisherNpub]);

                if ($publisherUser && !$publisherUser->isWriter()) {
                    $publisherUser->addRole(RolesEnum::WRITER->value);
                    $entityManager->flush();
                    $logger->info('Granted ROLE_WRITER to user', ['npub' => $publisherNpub]);
                }

                $redisViewStore->invalidateUserArticles($signedEvent['pubkey']);
                $redisViewStore->invalidateProfileTabs($signedEvent['pubkey']);

                $messageBus->dispatch(new RevalidateProfileCacheMessage($signedEvent['pubkey'], 'articles', true));
                $messageBus->dispatch(new RevalidateProfileCacheMessage($signedEvent['pubkey'], 'overview', true));
            }

            /** @var User $user */
            $user = $this->getUser();
            $relays = [];

            // Get pubkey from event for fallback (in case session expired)
            $eventPubkeyHex = $signedEvent['pubkey'] ?? null;

            if ($user) {
                // First try to get relays from User entity (persisted in DB)
                $storedRelays = $user->getRelays();
                if (!empty($storedRelays['write'] ?? $storedRelays['all'] ?? null)) {
                    // Prefer write relays for publishing, fallback to all
                    $relays = $storedRelays['write'] ?? $storedRelays['all'] ?? [];
                    $logger->debug('Using stored relays from User entity', ['relay_count' => count($relays)]);
                } else {
                    // Fallback to AuthorRelayService (cached with fallbacks, non-blocking)
                    try {
                        $pubkeyHex = NostrKeyUtil::npubToHex($user->getUserIdentifier());
                        $relays = $authorRelayService->getRelaysForPublishing($pubkeyHex);
                    } catch (\Exception $e) {
                        $logger->warning('Failed to get user relays, using fallbacks', ['error' => $e->getMessage()]);
                        $relays = $authorRelayService->getFallbackRelays();
                    }
                }
            } elseif ($eventPubkeyHex) {
                // Session expired but we have the pubkey from the signed event
                // Try to get relays from the User entity in database
                $logger->info('User session expired, attempting to get relays from event pubkey', ['pubkey' => $eventPubkeyHex]);
                try {
                    $key = new Key();
                    $eventNpub = $key->convertPublicKeyToBech32($eventPubkeyHex);
                    $eventUser = $userRepository->findOneBy(['npub' => $eventNpub]);

                    if ($eventUser) {
                        $storedRelays = $eventUser->getRelays();
                        if (!empty($storedRelays['write'] ?? $storedRelays['all'] ?? null)) {
                            $relays = $storedRelays['write'] ?? $storedRelays['all'] ?? [];
                            $logger->debug('Using stored relays from event user entity', ['relay_count' => count($relays)]);
                        }
                    }

                    // If we still don't have relays, fetch from AuthorRelayService
                    if (empty($relays)) {
                        $relays = $authorRelayService->getRelaysForPublishing($eventPubkeyHex);
                        $logger->debug('Fetched relays from AuthorRelayService for event pubkey', ['relay_count' => count($relays)]);
                    }
                } catch (\Exception $e) {
                    $logger->warning('Failed to get relays from event pubkey, using fallbacks', ['error' => $e->getMessage()]);
                    $relays = $authorRelayService->getFallbackRelays();
                }
            } else {
                // No user and no pubkey in event - use fallbacks
                $logger->warning('No user session and no pubkey in event, using fallback relays');
                $relays = $authorRelayService->getFallbackRelays();
            }

            // Publish to Nostr relays
            try {
                // Use shorter timeout (10s) to fail faster - article is already saved locally
                $rawResults = $nostrClient->publishEvent($eventObj, $relays, 10);
                $logger->info('Published to Nostr relays', [
                    'event_id' => $eventObj->getId(),
                    'results' => $rawResults
                ]);

                // Transform relay results into a simpler format for frontend
                $relayResults = $this->transformRelayResults($rawResults);

            } catch (\Exception $e) {
                // Log error but don't fail the request - article is saved locally
                $logger->error('Failed to publish to Nostr relays', [
                    'error' => $e->getMessage(),
                    'event_id' => $eventObj->getId()
                ]);
                $relayResults = [
                    'error' => $e->getMessage(),
                    'warning' => 'Article saved locally but relay publishing failed'
                ];
            }


            // Generate URL for the published article
            $redirectUrl = null;
            if (!$isDraft) {
                $key = new Key();
                $npub = $key->convertPublicKeyToBech32($article->getPubkey());
                $redirectUrl = $this->generateUrl('author-article-slug', [
                    'npub' => $npub,
                    'slug' => $article->getSlug()
                ]);
            }

            return new JsonResponse([
                'success' => true,
                'message' => $isDraft ? 'Draft saved successfully' : 'Article published successfully',
                'articleId' => $article->getId(),
                'slug' => $article->getSlug(),
                'isDraft' => $isDraft,
                'redirectUrl' => $redirectUrl,
                'relayResults' => $relayResults
            ]);

        } catch (\Exception $e) {
            $logger->error('Error during publish process', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Check if the article was saved to the database despite the error
            // This handles timeout scenarios where the article persisted but relay publishing timed out
            try {
                if (isset($signedEvent) && isset($signedEvent['tags'])) {
                    // Extract slug from event tags
                    $slug = null;
                    foreach ($signedEvent['tags'] as $tag) {
                        if (is_array($tag) && count($tag) >= 2 && $tag[0] === 'd') {
                            $slug = $tag[1];
                            break;
                        }
                    }

                    // Extract pubkey and check if article exists in DB
                    if ($slug && isset($signedEvent['pubkey'])) {
                        $isDraft = ($signedEvent['kind'] === KindsEnum::LONGFORM_DRAFT->value);
                        $kind = $isDraft ? KindsEnum::LONGFORM_DRAFT : KindsEnum::LONGFORM;

                        $savedArticle = $entityManager->getRepository(Article::class)
                            ->findOneBy([
                                'slug' => $slug,
                                'pubkey' => $signedEvent['pubkey'],
                                'kind' => $kind
                            ]);

                        if ($savedArticle) {
                            // Article was saved! Return success with warning about relay publish
                            $logger->info('Article found in DB despite error - likely timeout during relay publish', [
                                'slug' => $slug,
                                'article_id' => $savedArticle->getId()
                            ]);

                            $redirectUrl = null;
                            if (!$isDraft) {
                                $key = new Key();
                                $npub = $key->convertPublicKeyToBech32($savedArticle->getPubkey());
                                $redirectUrl = $this->generateUrl('author-article-slug', [
                                    'npub' => $npub,
                                    'slug' => $savedArticle->getSlug()
                                ]);
                            }

                            return new JsonResponse([
                                'success' => true,
                                'message' => $isDraft
                                    ? 'Draft saved successfully (relay publishing timed out)'
                                    : 'Article saved successfully (relay publishing may still be in progress)',
                                'articleId' => $savedArticle->getId(),
                                'slug' => $savedArticle->getSlug(),
                                'isDraft' => $isDraft,
                                'redirectUrl' => $redirectUrl,
                                'warning' => 'The article was saved locally. Relay publishing may have timed out.',
                                'relayResults' => ['error' => 'Timeout during relay publish']
                            ]);
                        }
                    }
                }
            } catch (\Exception $fallbackError) {
                // If the fallback check fails, log it but continue to return the original error
                $logger->error('Fallback article check failed', ['error' => $fallbackError->getMessage()]);
            }

            // Article was not found in DB or we couldn't check - return original error
            return new JsonResponse([
                'error' => 'Publishing failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Transform relay response objects into a simple array format for frontend
     */
    private function transformRelayResults(array $rawResults): array
    {
        $results = [];

        foreach ($rawResults as $relayUrl => $response) {
            $result = [
                'relay' => $relayUrl,
                'success' => false,
                'type' => 'unknown',
                'message' => ''
            ];

            // Check if it's a RelayResponse object with accessible properties
            if (is_object($response)) {
                // RelayResponseOk - indicates successful publish
                if (isset($response->type) && $response->type === 'OK') {
                    $result['success'] = true;
                    $result['type'] = 'ok';
                    $result['message'] = $response->message ?? '';
                }
                // RelayResponseAuth - relay requires auth (not necessarily a failure)
                elseif (isset($response->type) && $response->type === 'AUTH') {
                    $result['success'] = false; // Not confirmed published
                    $result['type'] = 'auth';
                    $result['message'] = 'Authentication required';
                }
                // RelayResponseNotice - informational message
                elseif (isset($response->type) && $response->type === 'NOTICE') {
                    $result['success'] = false;
                    $result['type'] = 'notice';
                    $result['message'] = $response->message ?? '';
                }
                // Check isSuccess property if available
                elseif (isset($response->isSuccess)) {
                    $result['success'] = (bool)$response->isSuccess;
                    $result['type'] = $response->type ?? 'unknown';
                    $result['message'] = $response->message ?? '';
                }
            }

            $results[] = $result;
        }

        return $results;
    }

    private function extractArticleDataFromEvent(array $event, array $formData): array
    {
        $data = [
            'title' => '',
            'summary' => '',
            'content' => $event['content'],
            'image' => '',
            'topics' => [],
            'slug' => ''
        ];

        // Extract data from tags
        foreach ($event['tags'] as $tag) {
            if (!is_array($tag) || count($tag) < 2) continue;

            switch ($tag[0]) {
                case 'd':
                    $data['slug'] = $tag[1];
                    break;
                case 'title':
                    $data['title'] = $tag[1];
                    break;
                case 'summary':
                    $data['summary'] = $tag[1];
                    break;
                case 'image':
                    $data['image'] = $tag[1];
                    break;
                case 't':
                    $data['topics'][] = $tag[1];
                    break;
            }
        }

        // Fallback to form data if not found in tags
        if (empty($data['title']) && !empty($formData['title'])) {
            $data['title'] = $formData['title'];
        }
        if (empty($data['summary']) && !empty($formData['summary'])) {
            $data['summary'] = $formData['summary'];
        }

        return $data;
    }
}
