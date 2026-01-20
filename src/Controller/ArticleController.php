<?php

namespace App\Controller;

use App\Entity\Article;
use App\Entity\User;
use App\Enum\KindsEnum;
use App\Form\EditorType;
use App\Service\HighlightService;
use App\Service\NostrClient;
use App\Service\Nostr\NostrEventParser;
use App\Service\RedisCacheService;
use App\Service\RedisViewStore;
use App\Util\CommonMark\Converter;
use Doctrine\ORM\EntityManagerInterface;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ArticleController  extends AbstractController
{
    /**
     * @throws \Exception
     */
    #[Route('/article/{naddr}', name: 'article-naddr', requirements: ['naddr' => '^(naddr1[0-9a-zA-Z]+)$'])]
    public function naddr(NostrClient $nostrClient, EntityManagerInterface $em, $naddr)
    {
        set_time_limit(120); // 2 minutes
        $decoded = new Bech32($naddr);

        if ($decoded->type !== 'naddr') {
            throw new \Exception('Invalid naddr');
        }

        /** @var NAddr $data */
        $data = $decoded->data;
        $slug = $data->identifier;
        $relays = $data->relays;
        $author = $data->pubkey;
        $kind = $data->kind;

        if ($kind !== KindsEnum::LONGFORM->value) {
            throw new \Exception('Not a long form article');
        }

        $found = $nostrClient->getLongFormFromNaddr($slug, $relays, $author, $kind);

        // Check if anything is in the database now
        $repository = $em->getRepository(Article::class);
        $article = $repository->findOneBy(['slug' => $slug, 'pubkey' => $author]);
        // If found, redirect to the article page
        if ($slug && $article) {
            return $this->redirectToRoute('article-slug', ['slug' => $slug]);
        }

        // Provide a more informative error message
        if (!$found) {
            throw new \Exception(sprintf(
                'No article found for slug "%s" by author %s. The article may not exist or the relays may be offline.',
                $slug,
                substr($author, 0, 8) . '...'
            ));
        }

        throw new \Exception('Article was retrieved from relays but could not be saved to the database.');
    }


    #[Route('/article/d/{slug}', name: 'article-slug', requirements: ['slug' => '.+'])]
    public function disambiguation($slug, EntityManagerInterface $entityManager): Response
    {
        $slug = urldecode($slug);
        $repository = $entityManager->getRepository(Article::class);
        $articles = $repository->findBy(['slug' => $slug], ['createdAt' => 'DESC']);
        $count = count($articles);
        if ($count === 0) {
            throw $this->createNotFoundException('No articles found for this slug');
        }

        // Group articles by author (pubkey)
        $articlesByAuthor = [];
        foreach ($articles as $article) {
            $pubkey = $article->getPubkey();
            if (!isset($articlesByAuthor[$pubkey])) {
                $articlesByAuthor[$pubkey] = [];
            }
            $articlesByAuthor[$pubkey][] = $article;
        }

        $uniqueAuthors = count($articlesByAuthor);

        // If only one author, redirect to their most recent article (already sorted by createdAt DESC)
        if ($uniqueAuthors === 1) {
            $key = new Key();
            $npub = $key->convertPublicKeyToBech32($articles[0]->getPubkey());
            return $this->redirectToRoute('author-article-slug', ['npub' => $npub, 'slug' => $slug]);
        }

        // Multiple authors: show disambiguation page with one article per author (most recent)
        $authors = [];
        $key = new Key();
        $uniqueArticles = [];
        foreach ($articlesByAuthor as $pubkey => $authorArticles) {
            // Get the most recent article for this author (first in array due to DESC sort)
            $mostRecentArticle = $authorArticles[0];
            $uniqueArticles[] = $mostRecentArticle;
            $authors[] = [
                'npub' => $key->convertPublicKeyToBech32($pubkey),
                'pubkey' => $pubkey,
                'createdAt' => $mostRecentArticle->getCreatedAt(),
            ];
        }

        return $this->render('pages/article_disambiguation.html.twig', [
            'slug' => $slug,
            'authors' => $authors,
            'articles' => $uniqueArticles
        ]);
    }

    #[Route('/p/{npub}/d/{slug}', name: 'author-article-slug', requirements: ['slug' => '.+'])]
    public function authorArticle(
        $npub,
        $slug,
        EntityManagerInterface $entityManager,
        RedisCacheService $redisCacheService,
        Converter $converter,
        LoggerInterface $logger,
        HighlightService $highlightService,
        NostrEventParser $eventParser
    ): Response
    {
        set_time_limit(300);
        ini_set('max_execution_time', '300');
        $slug = urldecode($slug);
        $key = new Key();
        $pubkey = $key->convertToHex($npub);
        $repository = $entityManager->getRepository(Article::class);
        $article = $repository->findOneBy(['slug' => $slug, 'pubkey' => $pubkey], ['createdAt' => 'DESC']);
        if (!$article) {
            throw $this->createNotFoundException('The article could not be found');
        }

        // Parse advanced metadata from raw event for zap splits etc.
        $advancedMetadata = null;
        if ($article->getRaw()) {
            $tags = $article->getRaw()['tags'] ?? [];
            $advancedMetadata = $eventParser->parseAdvancedMetadata($tags);
        }

        // Use cached processedHtml from database if available
        $htmlContent = $article->getProcessedHtml();
        $logger->info('Article content retrieval', [
            'article_id' => $article->getId(),
            'slug' => $article->getSlug(),
            'pubkey' => $article->getPubkey(),
            'has_cached_html' => $htmlContent !== null
        ]);

        if (!$htmlContent) {
            // Fall back to converting on-the-fly and save for future requests
            $htmlContent = $converter->convertToHTML($article->getContent());
            $article->setProcessedHtml($htmlContent);
            $entityManager->flush();
        }

        $author = $redisCacheService->getMetadata($article->getPubkey());
        $canEdit = false;
        $user = $this->getUser();
        if ($user) {
            try {
                $currentPubkey = $key->convertToHex($user->getUserIdentifier());
                $canEdit = ($currentPubkey === $article->getPubkey());
            } catch (\Throwable $e) {
                $canEdit = false;
            }
        }
        $canonical = $this->generateUrl('author-article-slug', ['npub' => $npub, 'slug' => $article->getSlug()], 0);
        $highlights = [];
        try {
            $articleCoordinate = '30023:' . $article->getPubkey() . ':' . $article->getSlug();
            $highlights = $highlightService->getHighlightsForArticle($articleCoordinate);
        } catch (\Exception $e) {}
        return $this->render('pages/article.html.twig', [
            'article' => $article,
            'author' => $author,
            'npub' => $npub,
            'content' => $htmlContent,
            'canEdit' => $canEdit,
            'canonical' => $canonical,
            'highlights' => $highlights,
            'advancedMetadata' => $advancedMetadata
        ]);
    }

    /**
     * Create new article
     * @throws \Exception
     */
    #[Route('/article-editor/create', name: 'editor-create')]
    #[Route('/article-editor/edit/{slug}', name: 'editor-edit-slug')]
    public function newArticle(
        Request $request,
        NostrClient $nostrClient,
        EntityManagerInterface $entityManager,
        NostrEventParser $eventParser,
        RedisViewStore $redisViewStore,
        $slug = null
    ): Response
    {
        $advancedMetadata = null;

        if (!$slug) {
            $article = new Article();
            $article->setKind(KindsEnum::LONGFORM);
            $article->setCreatedAt(new \DateTimeImmutable());
            $formAction = $this->generateUrl('editor-create');
        } else {
            $formAction = $this->generateUrl('editor-edit-slug', ['slug' => $slug]);
            $repository = $entityManager->getRepository(Article::class);
            $slug = urldecode($slug);
            $articles = $repository->findBy(['slug' => $slug]);
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
            // look for drafts on relays first, grab latest 5 from there
            // one week ago
            $since = new \DateTime();
            $aWeekAgo = $since->sub(new \DateInterval('P1D'))->getTimestamp();
            $nostrClient->getLongFormContentForPubkey($currentPubkey, $aWeekAgo, KindsEnum::LONGFORM_DRAFT->value);
            $drafts = $entityManager->getRepository(Article::class)
                ->findBy(['pubkey' => $currentPubkey, 'kind' => KindsEnum::LONGFORM_DRAFT], ['createdAt' => 'DESC'], 5);

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
        NostrClient $nostrClient
    ): Response {
        // This route previews another user's article, but sidebar shows current user's lists for navigation.
        $advancedMetadata = null;
        $key = new Key();
        $pubkey = $key->convertToHex($npub);
        $slug = urldecode($slug);
        $repository = $entityManager->getRepository(Article::class);
        $article = $repository->findOneBy(['slug' => $slug, 'pubkey' => $pubkey]);
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
        CacheItemPoolInterface $articlesCache,
        LoggerInterface $logger,
        NostrEventParser $eventParser
    ): JsonResponse {
        try {
            // Get JSON data
            $data = json_decode($request->getContent(), true);
            if (!$data || !isset($data['event'])) {
                return new JsonResponse(['error' => 'Invalid request data'], 400);
            }

            /* @var array $signedEvent */
            $signedEvent = $data['event'];
            // Convert the signed event array to a proper Event object
            $eventObj = new Event();
            $eventObj->setId($signedEvent['id']);
            $eventObj->setPublicKey($signedEvent['pubkey']);
            $eventObj->setCreatedAt($signedEvent['created_at']);
            $eventObj->setKind($signedEvent['kind']);
            $eventObj->setTags($signedEvent['tags']);
            $eventObj->setContent($signedEvent['content']);
            $eventObj->setSignature($signedEvent['sig']);

            if (!$eventObj->verify()) {
                return new JsonResponse(['error' => 'Event signature verification failed'], 400);
            }

            $formData = $data['formData'] ?? [];

            // Extract article data from the signed event
            $articleData = $this->extractArticleDataFromEvent($signedEvent, $formData);


            // Create new article
            $article = new Article();
            $article->setPubkey($signedEvent['pubkey']);
            $article->setKind(KindsEnum::LONGFORM);
            $article->setEventId($signedEvent['id']);
            $article->setSlug($articleData['slug']);
            $article->setTitle($articleData['title']);
            $article->setSummary($articleData['summary']);
            $article->setContent($articleData['content']);
            $article->setImage($articleData['image']);
            $article->setTopics($articleData['topics']);
            $article->setSig($signedEvent['sig']);
            $article->setRaw($signedEvent);
            $article->setCreatedAt(new \DateTimeImmutable('@' . $signedEvent['created_at']));
            $article->setPublishedAt(new \DateTimeImmutable());

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

            // Clear relevant caches
            $cacheKey = 'article_' . $article->getEventId();
            $articlesCache->delete($cacheKey);

            /** @var User $user */
            $user = $this->getUser();
            $relays = [];
            if ($user) {
                $relays = $user->getRelays();
            }

            // Publish to Nostr relays
            $relayResults = [];
            try {
                $rawResults = $nostrClient->publishEvent($eventObj, $relays);
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
                    'error' => $e->getMessage()
                ];
            }

            // Determine if this is a draft or published article
            $isDraft = ($signedEvent['kind'] === KindsEnum::LONGFORM_DRAFT->value);

            return new JsonResponse([
                'success' => true,
                'message' => $isDraft ? 'Draft saved successfully' : 'Article published successfully',
                'articleId' => $article->getId(),
                'slug' => $article->getSlug(),
                'isDraft' => $isDraft,
                'relayResults' => $relayResults
            ]);

        } catch (\Exception $e) {
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

    private function validateNostrEvent(array $event): void
    {
        $requiredFields = ['id', 'pubkey', 'created_at', 'kind', 'tags', 'content', 'sig'];

        foreach ($requiredFields as $field) {
            if (!isset($event[$field])) {
                throw new \InvalidArgumentException("Missing required field: $field");
            }
        }

        if ($event['kind'] !== 30023) {
            throw new \InvalidArgumentException('Invalid event kind. Expected 30023 for long-form content.');
        }

        // Validate d tag exists (required for NIP-33)
        $dTagFound = false;
        foreach ($event['tags'] as $tag) {
            if (is_array($tag) && count($tag) >= 2 && $tag[0] === 'd') {
                $dTagFound = true;
                break;
            }
        }

        if (!$dTagFound) {
            throw new \InvalidArgumentException('Missing required "d" tag for replaceable event');
        }
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
