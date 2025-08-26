<?php

namespace App\Controller;

use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Form\EditorType;
use App\Service\NostrClient;
use App\Service\RedisCacheService;
use App\Util\CommonMark\Converter;
use Doctrine\ORM\EntityManagerInterface;
use League\CommonMark\Exception\CommonMarkException;
use Mdanter\Ecc\Crypto\Signature\SchnorrSignature;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Workflow\WorkflowInterface;

class ArticleController  extends AbstractController
{
    /**
     * @throws \Exception
     */
    #[Route('/article/{naddr}', name: 'article-naddr', requirements: ['naddr' => '^(naddr1[0-9a-z]{59})$'])]
    public function naddr(NostrClient $nostrClient, $naddr)
    {
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

        $nostrClient->getLongFormFromNaddr($slug, $relays, $author, $kind);
        if ($slug) {
            return $this->redirectToRoute('article-slug', ['slug' => $slug]);
        }

        throw new \Exception('No article.');
    }

    /**
     * @throws InvalidArgumentException|CommonMarkException
     */
    #[Route('/article/d/{slug}', name: 'article-slug')]
    public function article(
        $slug,
        EntityManagerInterface $entityManager,
        RedisCacheService $redisCacheService,
        CacheItemPoolInterface $articlesCache,
        Converter $converter
    ): Response
    {

        set_time_limit(300); // 5 minutes
        ini_set('max_execution_time', '300');

        $article = null;
        // check if an item with same eventId already exists in the db
        $repository = $entityManager->getRepository(Article::class);
        $articles = $repository->findBy(['slug' => $slug]);
        $revisions = count($articles);

        if ($revisions === 0) {
            throw $this->createNotFoundException('The article could not be found');
        }

        if ($revisions > 1) {
            // sort articles by created at date
            usort($articles, function ($a, $b) {
                return $b->getCreatedAt() <=> $a->getCreatedAt();
            });
            // get the last article
            $article = end($articles);
        } else {
            $article = $articles[0];
        }

        $cacheKey = 'article_' . $article->getId();
        $cacheItem = $articlesCache->getItem($cacheKey);
        if (!$cacheItem->isHit()) {
            $cacheItem->set($converter->convertToHtml($article->getContent()));
            $articlesCache->save($cacheItem);
        }

        $key = new Key();
        $npub = $key->convertPublicKeyToBech32($article->getPubkey());
        $author = $redisCacheService->getMetadata($npub);


        return $this->render('pages/article.html.twig', [
            'article' => $article,
            'author' => $author,
            'npub' => $npub,
            'content' => $cacheItem->get(),
        ]);
    }

    /**
     * Fetch complete event to show as preview
     * POST data contains an object with request params
     */
    #[Route('/preview/', name: 'article-preview-event', methods: ['POST'])]
    public function articlePreviewEvent(
        Request $request,
        NostrClient $nostrClient,
        RedisCacheService $redisCacheService,
        CacheItemPoolInterface $articlesCache
    ): Response {
        $data = $request->getContent();
        // descriptor is an object with properties type, identifier and data
        // if type === 'nevent', identifier is the event id
        // if type === 'naddr', identifier is the naddr
        // if type === 'nprofile', identifier is the npub
        $descriptor = json_decode($data);
        $previewData = [];

        // if nprofile, get from redis cache
        if ($descriptor->type === 'nprofile') {
            $hint = json_decode($descriptor->decoded);
            $key = new Key();
            $npub = $key->convertPublicKeyToBech32($hint->pubkey);
            $metadata = $redisCacheService->getMetadata($npub);
            $metadata->npub = $npub;
            $metadata->pubkey = $hint->pubkey;
            $metadata->type = 'nprofile';
            // Render the NostrPreviewContent component with the preview data
            $html = $this->renderView('components/Molecules/NostrPreviewContent.html.twig', [
                'preview' => $metadata
            ]);
        } else {
            // For nevent or naddr, fetch the event data
            try {
                $previewData = $nostrClient->getEventFromDescriptor($descriptor);
                $previewData->type = $descriptor->type; // Add type to the preview data
                // Render the NostrPreviewContent component with the preview data
                $html = $this->renderView('components/Molecules/NostrPreviewContent.html.twig', [
                    'preview' => $previewData
                ]);
            } catch (\Exception $e) {
                $html = '<span>Error fetching preview: ' . htmlspecialchars($e->getMessage()) . '</span>';
            }
        }


        return new Response(
            $html,
            Response::HTTP_OK,
            ['Content-Type' => 'text/html']
        );
    }

    /**
     * Create new article
     * @throws InvalidArgumentException
     * @throws \Exception
     */
    #[Route('/article-editor/create', name: 'editor-create')]
    #[Route('/article-editor/edit/{id}', name: 'editor-edit')]
    public function newArticle(Request $request, EntityManagerInterface $entityManager, CacheItemPoolInterface $articlesCache,
                               WorkflowInterface $articlePublishingWorkflow, Article $article = null): Response
    {
        if (!$article) {
            $article = new Article();
            $article->setKind(KindsEnum::LONGFORM);
            $article->setCreatedAt(new \DateTimeImmutable());
            $formAction = $this->generateUrl('editor-create');
        } else {
            $formAction = $this->generateUrl('editor-edit', ['id' => $article->getId()]);
        }

        $form = $this->createForm(EditorType::class, $article, ['action' => $formAction]);
        $form->handleRequest($request);

        // Step 3: Check if the form is submitted and valid
        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->getUser();
            $key = new Key();
            $currentPubkey = $key->convertToHex($user->getUserIdentifier());

            if ($article->getPubkey() === null) {
                $article->setPubkey($currentPubkey);
            }

            // Check which button was clicked
            if ($form->getClickedButton() === $form->get('actions')->get('submit')) {
                // Save button was clicked, handle the "Publish" action
                $this->addFlash('success', 'Product published!');
            } elseif ($form->getClickedButton() === $form->get('actions')->get('draft')) {
                // Save and Publish button was clicked, handle the "Draft" action
                $this->addFlash('success', 'Product saved as draft!');
            } elseif ($form->getClickedButton() === $form->get('actions')->get('preview')) {
                // Preview button was clicked, handle the "Preview" action
                // construct slug from title and save to tags
                $slugger = new AsciiSlugger();
                $slug = $slugger->slug($article->getTitle())->lower();
                $article->setSig(''); // clear the sig
                $article->setSlug($slug);
                $cacheKey = 'article_' . $currentPubkey . '_' . $article->getSlug();
                $cacheItem = $articlesCache->getItem($cacheKey);
                $cacheItem->set($article);
                $articlesCache->save($cacheItem);

                return $this->redirectToRoute('article-preview', ['d' => $article->getSlug()]);
            }
        }

        // load template with content editor
        return $this->render('pages/editor.html.twig', [
            'article' => $article,
            'form' => $this->createForm(EditorType::class, $article)->createView(),
        ]);
    }

    /**
     * Preview article
     * @throws InvalidArgumentException
     * @throws CommonMarkException
     * @throws \Exception
     */
    #[Route('/article-preview/{d}', name: 'article-preview')]
    public function preview($d, Converter $converter,
                            CacheItemPoolInterface $articlesCache): Response
    {
        $user = $this->getUser();
        $key = new Key();
        $currentPubkey = $key->convertToHex($user->getUserIdentifier());

        $cacheKey = 'article_' . $currentPubkey . '_' . $d;
        $cacheItem = $articlesCache->getItem($cacheKey);
        $article = $cacheItem->get();

        $content = $converter->convertToHtml($article->getContent());

        return $this->render('pages/article.html.twig', [
            'article' => $article,
            'content' => $content,
            'author' => $user->getMetadata(),
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
        WorkflowInterface $articlePublishingWorkflow,
        CsrfTokenManagerInterface $csrfTokenManager
    ): JsonResponse {
        try {
            // Verify CSRF token
            $csrfToken = $request->headers->get('X-CSRF-TOKEN');
            if (!$csrfTokenManager->isTokenValid(new \Symfony\Component\Security\Csrf\CsrfToken('nostr_publish', $csrfToken))) {
                return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
            }

            // Get JSON data
            $data = json_decode($request->getContent(), true);
            if (!$data || !isset($data['event'])) {
                return new JsonResponse(['error' => 'Invalid request data'], 400);
            }

            $signedEvent = $data['event'];
            $formData = $data['formData'] ?? [];

            // Validate Nostr event structure
            $this->validateNostrEvent($signedEvent);

            // Verify the event signature
            if (!$this->verifyNostrSignature($signedEvent)) {
                return new JsonResponse(['error' => 'Invalid event signature'], 400);
            }

            // Check if user is authenticated and matches the event pubkey
            $user = $this->getUser();
            if (!$user) {
                return new JsonResponse(['error' => 'User not authenticated'], 401);
            }

            $key = new Key();
            $currentPubkey = $key->convertToHex($user->getUserIdentifier());

            if ($signedEvent['pubkey'] !== $currentPubkey) {
                return new JsonResponse(['error' => 'Event pubkey does not match authenticated user'], 403);
            }

            // Extract article data from the signed event
            $articleData = $this->extractArticleDataFromEvent($signedEvent, $formData);

            // Check if article with same slug already exists for this author
            $repository = $entityManager->getRepository(Article::class);
            $existingArticle = $repository->findOneBy([
                'slug' => $articleData['slug'],
                'pubkey' => $currentPubkey
            ]);

            if ($existingArticle) {
                // Update existing article (NIP-33 replaceable event)
                $article = $existingArticle;
            } else {
                // Create new article
                $article = new Article();
                $article->setPubkey($currentPubkey);
                $article->setKind(KindsEnum::LONGFORM);
            }

            // Update article properties
            $article->setEventId($this->generateEventId($signedEvent));
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

            // Check workflow permissions
            if ($articlePublishingWorkflow->can($article, 'publish')) {
                $articlePublishingWorkflow->apply($article, 'publish');
            }

            // Save to database
            $entityManager->persist($article);
            $entityManager->flush();

            // Optionally publish to Nostr relays
            try {
                // Convert the signed event array to a proper Event object
                $eventObj = new \swentel\nostr\Event\Event();
                $eventObj->setId($signedEvent['id']);
                $eventObj->setPublicKey($signedEvent['pubkey']);
                $eventObj->setCreatedAt($signedEvent['created_at']);
                $eventObj->setKind($signedEvent['kind']);
                $eventObj->setTags($signedEvent['tags']);
                $eventObj->setContent($signedEvent['content']);
                $eventObj->setSignature($signedEvent['sig']);

                // Get user's relays or use default ones
                $relays = [];
                if ($user && method_exists($user, 'getRelays') && $user->getRelays()) {
                    foreach ($user->getRelays() as $relayArr) {
                        if (isset($relayArr[1]) && isset($relayArr[2]) && $relayArr[2] === 'write') {
                            $relays[] = $relayArr[1];
                        }
                    }
                }

                // Fallback to default relays if no user relays found
                if (empty($relays)) {
                    $relays = [
                        'wss://relay.damus.io',
                        'wss://relay.primal.net',
                        'wss://nos.lol'
                    ];
                }

                $nostrClient->publishEvent($eventObj, $relays);
            } catch (\Exception $e) {
                // Log error but don't fail the request - article is saved locally
                error_log('Failed to publish to Nostr relays: ' . $e->getMessage());
            }

            return new JsonResponse([
                'success' => true,
                'message' => 'Article published successfully',
                'articleId' => $article->getId(),
                'slug' => $article->getSlug()
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Publishing failed: ' . $e->getMessage()
            ], 500);
        }
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

    private function verifyNostrSignature(array $event): bool
    {
        try {
            // Reconstruct the event ID
            $serializedEvent = json_encode([
                0,
                $event['pubkey'],
                $event['created_at'],
                $event['kind'],
                $event['tags'],
                $event['content']
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $eventId = hash('sha256', $serializedEvent);

            // Verify the event ID matches
            if ($eventId !== $event['id']) {
                return false;
            }

            return (new SchnorrSignature())->verify($event['pubkey'], $event['sig'], $event['id']);
        } catch (\Exception $e) {
            return false;
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

    private function generateEventId(array $event): string
    {
        return $event['id'];
    }

    // ...existing code...

}
