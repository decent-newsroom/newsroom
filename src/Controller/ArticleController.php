<?php

namespace App\Controller;

use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Form\EditorType;
use App\Service\NostrClient;
use App\Service\RedisCacheService;
use App\Util\CommonMark\Converter;
use Doctrine\ORM\EntityManagerInterface;
use FOS\ElasticaBundle\Finder\PaginatedFinderInterface;
use League\CommonMark\Exception\CommonMarkException;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class ArticleController  extends AbstractController
{
    /**
     * @throws \Exception
     */
    #[Route('/article/{naddr}', name: 'article-naddr', requirements: ['naddr' => '^(naddr1[0-9a-zA-Z]+)$'])]
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
    #[Route('/article/d/{slug}', name: 'article-slug', requirements: ['slug' => '.+'])]
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
        // slug might be url encoded, decode it
        $slug = urldecode($slug);
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
        }

        $article = $articles[0];

        $cacheKey = 'article_' . $article->getEventId();
        $cacheItem = $articlesCache->getItem($cacheKey);
        //if (!$cacheItem->isHit()) {
            $cacheItem->set($converter->convertToHTML($article->getContent()));
            $articlesCache->save($cacheItem);
        //}

        $key = new Key();
        $npub = $key->convertPublicKeyToBech32($article->getPubkey());
        $author = $redisCacheService->getMetadata($article->getPubkey());

        // determine whether the logged-in user is the author
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

        $canonical = $this->generateUrl('article-slug', ['slug' => $article->getSlug()], 0);

        return $this->render('pages/article.html.twig', [
            'article' => $article,
            'author' => $author,
            'npub' => $npub,
            'content' => $cacheItem->get(),
            'canEdit' => $canEdit,
            'canonical' => $canonical
        ]);
    }

    /**
     * Create new article
     * @throws \Exception
     */
    #[Route('/article-editor/create', name: 'editor-create')]
    #[Route('/article-editor/edit/{slug}', name: 'editor-edit-slug')]
    public function newArticle(Request $request, EntityManagerInterface $entityManager, $slug = null): Response
    {
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
        }

        $recentArticles = [];
        $drafts = [];

        $user = $this->getUser();
        if (!!$user) {
            $key = new Key();
            $currentPubkey = $key->convertToHex($user->getUserIdentifier());
            $recentArticles = $entityManager->getRepository(Article::class)
                ->findBy(['pubkey' => $currentPubkey, 'kind' => KindsEnum::LONGFORM], ['createdAt' => 'DESC'], 5);
            $drafts = $entityManager->getRepository(Article::class)
                ->findBy(['pubkey' => $currentPubkey, 'kind' => KindsEnum::LONGFORM_DRAFT], ['createdAt' => 'DESC'], 5);

            if ($article->getPubkey() === null) {
                $article->setPubkey($currentPubkey);
            }
        }

        $form = $this->createForm(EditorType::class, $article, ['action' => $formAction]);
        $form->handleRequest($request);

        // load template with content editor
        return $this->render('pages/editor.html.twig', [
            'article' => $article,
            'form' => $form->createView(),
            'recentArticles' => $recentArticles,
            'drafts' => $drafts,
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
        CsrfTokenManagerInterface $csrfTokenManager
    ): JsonResponse {
        try {
            // Verify CSRF token
            $csrfToken = $request->headers->get('X-CSRF-TOKEN');
            if (!$csrfTokenManager->isTokenValid(new CsrfToken('nostr_publish', $csrfToken))) {
                return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
            }

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

            // Check if user is authenticated and matches the event pubkey
            $user = $this->getUser();
            if (!$user) {
                return new JsonResponse(['error' => 'User not authenticated'], 401);
            }

            $formData = $data['formData'] ?? [];

            $key = new Key();
            $currentPubkey = $key->convertToHex($user->getUserIdentifier());

            if ($signedEvent['pubkey'] !== $currentPubkey) {
                return new JsonResponse(['error' => 'Event pubkey does not match authenticated user'], 403);
            }

            // Extract article data from the signed event
            $articleData = $this->extractArticleDataFromEvent($signedEvent, $formData);


            // Create new article
            $article = new Article();
            $article->setPubkey($currentPubkey);
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

            // Save to database
            $entityManager->persist($article);
            $entityManager->flush();

            // Clear relevant caches
            $cacheKey = 'article_' . $article->getEventId();
            $articlesCache->delete($cacheKey);

            // Publish to Nostr relays
            try {
                $nostrClient->publishEvent($eventObj, []);
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
