<?php

namespace App\Controller\Reader;

use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Enum\RolesEnum;
use App\Entity\User;
use App\Message\FetchEventFromRelaysMessage;
use App\Message\PrefetchNostrEmbedsMessage;
use App\Service\ArticlePublicationIndexer;
use App\Service\Cache\RedisCacheService;
use App\Service\EmbedReferenceExtractor;
use App\Service\HighlightService;
use App\Service\ReadingListNavigationService;
use App\Service\Nostr\NostrEventParser;
use App\Service\VanityNameService;
use App\Util\CommonMark\Converter;
use Doctrine\ORM\EntityManagerInterface;
use League\CommonMark\Exception\CommonMarkException;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class ArticleController  extends AbstractController
{
    public function __construct(
        private readonly VanityNameService $vanityNameService,
    ) {}

    /**
     * True when the current viewer is allowed to read Essayist-exclusive
     * articles (logged-in member, candidate, early bird, or admin).
     */
    private function viewerCanSeeEssayistExclusive(): bool
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return false;
        }
        $roles = $user->getRoles();
        return in_array('ROLE_ADMIN', $roles, true)
            || in_array(RolesEnum::ESSAYIST_MEMBER->value, $roles, true)
            || in_array(RolesEnum::ESSAYIST_EARLY_BIRD->value, $roles, true);
    }

    /**
     * Resolve vanity name to npub, or redirect npub to vanity if exists
     * @return string|Response|array Returns npub string, Response redirect, or array with npub and vanity info
     */
    private function resolveVanityOrRedirect(?string $npub, ?string $vanity, string $routeName, array $params = []): string|Response|array
    {
        if ($vanity !== null) {
            // Vanity name provided, resolve it to npub and return both
            $vanityObj = $this->vanityNameService->getActiveByVanityName($vanity);
            if ($vanityObj === null) {
                return $this->render('pages/article_not_found.html.twig', [
                    'message' => 'Profile not found for vanity name: ' . $vanity,
                    'searchQuery' => $vanity
                ]);
            }
            return [
                'npub' => $vanityObj->getNpub(),
                'vanity' => $vanity,
                'useVanity' => true
            ];
        }

        if ($npub !== null) {
            // Npub provided, check if it has a vanity name and redirect
            $vanityObj = $this->vanityNameService->getActiveByNpub($npub);
            if ($vanityObj !== null) {
                return $this->redirectToRoute($routeName, array_merge(['vanity' => $vanityObj->getVanityName()], $params), 301);
            }
            return [
                'npub' => $npub,
                'vanity' => null,
                'useVanity' => false
            ];
        }

        return $this->render('pages/article_not_found.html.twig', [
            'message' => 'Profile not found.',
            'searchQuery' => ''
        ]);
    }
    /**
     * Legacy route — delegates all naddr handling to EventController.
     *
     * EventController already does: DB lookup (event table) → sync hint-relay
     * fetch → async fallback → article projection → redirect to author-article-slug.
     * Duplicating that logic here caused bugs (different table lookups, missing
     * projections) and the loading template already reloads to /e/… anyway.
     */
    #[Route('/article/{naddr}', name: 'article-naddr', requirements: ['naddr' => '^(naddr1[0-9a-zA-Z]+)$'])]
    public function naddr($naddr): Response
    {
        return $this->redirectToRoute('nevent', ['nevent' => $naddr]);
    }



    #[Route('/article/d/{slug}/draft', name: 'draft-slug', requirements: ['slug' => '.+'], priority: 10)]
    public function draftSlug($slug, EntityManagerInterface $entityManager): Response
    {
        // Drafts require authentication
        if (!$this->getUser()) {
            throw $this->createAccessDeniedException('You must be logged in to view drafts.');
        }

        $slug = urldecode($slug);
        try {
            $key = new Key();
            $currentPubkey = $key->convertToHex($this->getUser()->getUserIdentifier());
        } catch (\Throwable) {
            throw $this->createAccessDeniedException('Invalid user identifier.');
        }

        // Only find drafts belonging to the current user
        $repository = $entityManager->getRepository(Article::class);
        $draft = $repository->findOneBy([
            'slug' => $slug,
            'pubkey' => $currentPubkey,
            'kind' => KindsEnum::LONGFORM_DRAFT
        ], ['createdAt' => 'DESC']);

        if (!$draft) {
            return $this->render('pages/article_not_found.html.twig', [
                'message' => 'Draft not found or you do not have permission to view it.',
                'searchQuery' => $slug
            ]);
        }

        // Redirect to the full author-draft-slug route
        try {
            $npub = $key->convertPublicKeyToBech32($currentPubkey);
        } catch (\Throwable) {
            throw $this->createNotFoundException('Could not encode author key.');
        }
        return $this->redirectToRoute('author-draft-slug', ['npub' => $npub, 'slug' => $slug]);
    }

    /**
     * Handles disambiguation for articles with the same slug by different authors.
     * If only one found, redirects to 'author-article-slug'.
     *
     * @param $slug
     * @param EntityManagerInterface $entityManager
     * @return Response
     */
    #[Route('/article/d/{slug}', name: 'article-slug', requirements: ['slug' => '.+'], priority: 10)]
    public function disambiguation($slug, EntityManagerInterface $entityManager): Response
    {
        $slug = urldecode($slug);
        $repository = $entityManager->getRepository(Article::class);
        $articles = $repository->findBy(['slug' => $slug], ['createdAt' => 'DESC']);
        $count = count($articles);
        if ($count === 0) {
            return $this->render('pages/article_not_found.html.twig', [
                'message' => 'No articles found for this slug. Try searching or pasting a Nostr address (naddr) below.',
                'searchQuery' => $slug
            ]);
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
            try {
                $key = new Key();
                $npub = $key->convertPublicKeyToBech32($articles[0]->getPubkey());
            } catch (\Throwable) {
                throw $this->createNotFoundException('Invalid author key.');
            }
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
            try {
                $npubEncoded = $key->convertPublicKeyToBech32($pubkey);
            } catch (\Throwable) {
                continue; // Skip authors with malformed pubkeys
            }
            $authors[] = [
                'npub' => $npubEncoded,
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

    #[Route('/p/{npub}/d/{slug}/draft', name: 'author-draft-slug', requirements: ['slug' => '.+'], priority: 5)]
    #[Route('/{vanity}/d/{slug}/draft', name: 'author-vanity-draft-slug', requirements: ['slug' => '.+'], priority: 5)]
    public function authorDraft(
        $slug,
        EntityManagerInterface $entityManager,
        RedisCacheService $redisCacheService,
        Converter $converter,
        LoggerInterface $logger,
        HighlightService $highlightService,
        NostrEventParser $eventParser,
        $npub = null,
        $vanity = null
    ): Response
    {
        // Drafts require authentication
        if (!$this->getUser()) {
            throw $this->createAccessDeniedException('You must be logged in to view drafts.');
        }

        $resolved = $this->resolveVanityOrRedirect($npub, $vanity, 'author-vanity-draft-slug', ['slug' => $slug]);
        if ($resolved instanceof Response) {
            return $resolved;
        }

        $npub = $resolved['npub'];

        $slug = urldecode($slug);
        try {
            $key = new Key();
            $pubkey = $key->convertToHex($npub);
        } catch (\Throwable) {
            throw $this->createNotFoundException('Invalid author identifier.');
        }

        // Verify the user is the author of this draft
        try {
            $currentPubkey = $key->convertToHex($this->getUser()->getUserIdentifier());
        } catch (\Throwable) {
            throw $this->createAccessDeniedException('Invalid user identifier.');
        }
        if ($currentPubkey !== $pubkey) {
            throw $this->createAccessDeniedException('You can only view your own drafts.');
        }

        $repository = $entityManager->getRepository(Article::class);
        $draft = $repository->findOneBy(['slug' => $slug, 'pubkey' => $pubkey, 'kind' => KindsEnum::LONGFORM_DRAFT], ['createdAt' => 'DESC']);
        if (!$draft) {
            return $this->render('pages/article_not_found.html.twig', [
                'message' => 'The draft could not be found. It may have been deleted or published.',
                'searchQuery' => $slug
            ]);
        }

        // Parse advanced metadata from raw event for zap splits etc.
        $advancedMetadata = null;
        if ($draft->getRaw()) {
            $tags = $draft->getRaw()['tags'] ?? [];
            $advancedMetadata = $eventParser->parseAdvancedMetadata($tags);
        }

        // Use cached processedHtml from database if available
        $htmlContent = $draft->getProcessedHtml();
        $logger->info('Draft content retrieval', [
            'article_id' => $draft->getId(),
            'slug' => $draft->getSlug(),
            'pubkey' => $draft->getPubkey(),
            'has_cached_html' => $htmlContent !== null
        ]);

        if (!$htmlContent) {
            // Fall back to converting on-the-fly.
            // Avoid flushing during a web request in FrankenPHP worker mode: if the DB connection is stale
            // or flush fails, it may poison the EntityManager for subsequent requests in this worker.
            try {
                $htmlContent = $converter->convertToHTML(
                    $draft->getContent(),
                    null,
                    $draft->getKind()?->value,
                    $draft->getRaw()['tags'] ?? null,
                );
                $draft->setProcessedHtml($htmlContent);
            } catch (\Throwable $e) {
                $logger->error('Error converting draft content to HTML', [
                    'article_id' => $draft->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $authorMetadata = $redisCacheService->getMetadata($draft->getPubkey());
        $author = $authorMetadata->toStdClass(); // Convert to stdClass for template compatibility
        $canEdit = false;
        $user = $this->getUser();
        if ($user) {
            try {
                $currentPubkey = $key->convertToHex($user->getUserIdentifier());
                $canEdit = ($currentPubkey === $draft->getPubkey());
            } catch (\Throwable $e) {
                $canEdit = false;
            }
        }
        $canonical = $this->generateUrl('author-draft-slug', ['npub' => $npub, 'slug' => $draft->getSlug()], 0);
        $highlights = [];
        try {
            $draftCoordinate = '30024:' . $draft->getPubkey() . ':' . $draft->getSlug();
            $highlights = $highlightService->getHighlightsForArticle($draftCoordinate);
        } catch (\Throwable $e) {
            // Best-effort only
        }

        // Reuse article.html.twig template - drafts use the same Article entity
        return $this->render('pages/article.html.twig', [
            'article' => $draft,  // Pass draft as article since they share the same entity
            'author' => $author,
            'npub' => $npub,
            'content' => $htmlContent,
            'canEdit' => $canEdit,
            'canonical' => $canonical,
            'highlights' => $highlights,
            'advancedMetadata' => $advancedMetadata,
            'isDraft' => true  // Flag to identify this is a draft view
        ]);
    }

    #[Route('/p/{npub}/d/{slug}/aside', name: 'article-aside-frame', requirements: ['slug' => '.+'], priority: 6)]
    public function articleAsideFrame(
        string $slug,
        ArticlePublicationIndexer $publicationIndexer,
        string $npub,
    ): Response {
        $slug = urldecode($slug);
        $publications = [];
        try {
            $key = new Key();
            $pubkey = $key->convertToHex($npub);
            $publications = $publicationIndexer->findPublicationsForArticle($pubkey, $slug);
        } catch (\Throwable) {}

        return $this->render('pages/_article_aside_frame.html.twig', [
            'publications' => $publications,
        ]);
    }

    #[Route('/p/{npub}/d/{slug}/list-nav', name: 'article-list-nav-frame', requirements: ['slug' => '.+'], priority: 6)]
    public function articleListNavFrame(
        string $slug,
        ReadingListNavigationService $readingListNavigation,
        string $npub,
    ): Response {
        $slug = urldecode($slug);
        $listNav = null;
        try {
            $key = new Key();
            $pubkey = $key->convertToHex($npub);
            $listNav = $readingListNavigation->findNavigation('30023:' . $pubkey . ':' . $slug);
        } catch (\Throwable) {}

        return $this->render('pages/_article_list_nav_frame.html.twig', [
            'listNav' => $listNav,
        ]);
    }

    #[Route('/p/{npub}/d/{slug}', name: 'author-article-slug', requirements: ['slug' => '.+'], priority: 5)]
    #[Route('/{vanity}/d/{slug}', name: 'author-vanity-article-slug', requirements: ['slug' => '.+'], priority: 5)]
    public function authorArticle(
        $slug,
        EntityManagerInterface $entityManager,
        RedisCacheService $redisCacheService,
        Converter $converter,
        LoggerInterface $logger,
        HighlightService $highlightService,
        NostrEventParser $eventParser,
        EmbedReferenceExtractor $embedExtractor,
        MessageBusInterface $bus,
        $npub = null,
        $vanity = null
    ): Response
    {
        $resolved = $this->resolveVanityOrRedirect($npub, $vanity, 'author-vanity-article-slug', ['slug' => $slug]);
        if ($resolved instanceof Response) {
            return $resolved;
        }

        $npub = $resolved['npub'];

        $slug = urldecode($slug);
        try {
            $key = new Key();
            $pubkey = $key->convertToHex($npub);
        } catch (\Throwable) {
            throw $this->createNotFoundException('Invalid author identifier.');
        }
        $repository = $entityManager->getRepository(Article::class);
        $article = $repository->findOneBy(['slug' => $slug, 'pubkey' => $pubkey], ['createdAt' => 'DESC']);

        if (!$article) {
            // Article not in local DB — dispatch async relay fetch and show a
            // loading page.  The worker (FetchEventFromRelaysHandler) will
            // persist the event and publish a Mercure update; the browser
            // Stimulus controller will reload this URL once the article lands.
            $lookupKey = 'article:' . md5($pubkey . ':' . $slug);
            $logger->info('Article not in DB, dispatching async relay fetch', [
                'npub' => $npub,
                'slug' => $slug,
                'lookupKey' => $lookupKey,
            ]);
            try {
                $bus->dispatch(new FetchEventFromRelaysMessage(
                    lookupKey: $lookupKey,
                    type: 'naddr',
                    kind: KindsEnum::LONGFORM->value,
                    pubkey: $pubkey,
                    identifier: $slug,
                ));
            } catch (\Throwable $e) {
                $logger->warning('Could not dispatch async article fetch', ['error' => $e->getMessage()]);
            }

            return $this->render('pages/article_loading.html.twig', [
                'lookupKey' => $lookupKey,
                'reloadUrl' => $this->generateUrl('author-article-slug', ['npub' => $npub, 'slug' => $slug]),
            ]);
        }

        // Gate Essayist-exclusive articles: only logged-in members or admins
        // may access. Everyone else gets the standard not-found view so the
        // existence of the exclusive is not disclosed.
        if ($article->isEssayistExclusive() && !$this->viewerCanSeeEssayistExclusive()) {
            return $this->render('pages/article_not_found.html.twig', [
                'message' => 'The article could not be found.',
                'searchQuery' => $slug
            ]);
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
            try {
                // Fall back to converting on-the-fly.
                // Avoid flushing during a web request in FrankenPHP worker mode for stability.
                $htmlContent = $converter->convertToHTML(
                    $article->getContent(),
                    null,
                    $article->getKind()?->value,
                    $article->getRaw()['tags'] ?? null,
                );
                $article->setProcessedHtml($htmlContent);
            } catch (\Exception|CommonMarkException $e) {
                $logger->error('Error converting article content to HTML', [
                    'article_id' => $article->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        $authorMetadata = $redisCacheService->getMetadata($article->getPubkey());
        $author = $authorMetadata->toStdClass(); // Convert to stdClass for template compatibility
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

        // ── Async prefetch of unresolved nostr embeds in article content ──────
        // Any nostr: references in the article that weren't in the local DB at
        // conversion time are sitting as placeholder divs in processedHtml.
        // Dispatch a message so the worker can batch-fetch them from relays; on
        // subsequent visits they will render as rich cards server-side.
        try {
            $refs = $embedExtractor->extractFromHtml($htmlContent);
            if (!empty($refs['eventIds']) || !empty($refs['coordinates'])) {
                $articleCoordinate = '30023:' . $article->getPubkey() . ':' . $article->getSlug();
                $bus->dispatch(new PrefetchNostrEmbedsMessage(
                    $articleCoordinate,
                    $refs['eventIds'],
                    $refs['coordinates'],
                    $refs['relayHints'],
                ));
                $logger->info('Dispatched embed prefetch for article', [
                    'coordinate'  => $articleCoordinate,
                    'event_ids'   => count($refs['eventIds']),
                    'coordinates' => count($refs['coordinates']),
                ]);
            }
        } catch (\Throwable $e) {
            // Non-critical — transport may be temporarily unavailable
            $logger->debug('Could not dispatch embed prefetch', ['error' => $e->getMessage()]);
        }
        $highlights = [];
        try {
            $articleCoordinate = '30023:' . $article->getPubkey() . ':' . $article->getSlug();
            $highlights = $highlightService->getHighlightsForArticle($articleCoordinate);
        } catch (\Throwable $e) {
            // Best-effort only
        }

        try {
            return $this->render('pages/article.html.twig', [
                'article' => $article,
                'author' => $author,
                'npub' => $npub,
                'content' => $htmlContent,
                'canEdit' => $canEdit,
                'canonical' => $canonical,
                'highlights' => $highlights,
                'advancedMetadata' => $advancedMetadata,
            ]);
        } catch (\Throwable $e) {
            $logger->critical('ARTICLE RENDER CRASHED', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => substr($e->getTraceAsString(), 0, 2000),
                'slug' => $slug,
                'is_anonymous' => $this->getUser() === null,
            ]);
            throw $e;
        }
    }

}
