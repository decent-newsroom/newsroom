<?php

namespace App\Controller\Reader;

use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Service\ArticlePublicationIndexer;
use App\Service\Reader\ArticleAccessService;
use App\Service\Reader\ArticlePageLoader;
use App\Service\HighlightService;
use App\Service\ReadingListNavigationService;
use App\Service\VanityNameService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ArticleController  extends AbstractController
{
    public function __construct(
        private readonly VanityNameService $vanityNameService,
    ) {}

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
        ArticlePageLoader $articlePageLoader,
        $npub = null,
        $vanity = null
    ): Response
    {
        $resolved = $this->resolveVanityOrRedirect($npub, $vanity, 'author-vanity-draft-slug', ['slug' => $slug]);
        if ($resolved instanceof Response) {
            return $resolved;
        }

        $npub = $resolved['npub'];

        try {
            $page = $articlePageLoader->loadDraftArticle($npub, $slug, $this->getUser());
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Invalid author identifier.');
        }

        if ($page->isNotFound()) {
            return $this->render('pages/article_not_found.html.twig', $page->notFoundTemplateParameters());
        }

        return $this->render('pages/article.html.twig', $page->articleTemplateParameters());
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

    #[Route('/p/{npub}/d/{slug}/comments', name: 'article-comments-frame', requirements: ['slug' => '.+'], priority: 6)]
    public function articleCommentsFrame(
        string $slug,
        string $npub,
    ): Response {
        $slug = urldecode($slug);

        try {
            $key = new Key();
            $pubkey = $key->convertToHex($npub);
            $articleRoot = '30023:' . $pubkey . ':' . $slug;
        } catch (\Throwable) {
            return $this->render('pages/_article_comments_frame.html.twig');
        }

        return $this->render('pages/_article_comments_frame.html.twig', [
            'articleRoot' => $articleRoot,
            'articlePubkey' => $pubkey,
        ]);
    }

    #[Route('/p/{npub}/d/{slug}/highlights', name: 'article-highlights-frame', requirements: ['slug' => '.+'], priority: 6)]
    public function articleHighlightsFrame(
        string $slug,
        string $npub,
        HighlightService $highlightService,
    ): Response {
        $slug = urldecode($slug);
        $highlights = [];

        try {
            $key = new Key();
            $pubkey = $key->convertToHex($npub);
            $articleCoordinate = '30023:' . $pubkey . ':' . $slug;
            $highlights = $highlightService->getHighlightsForArticle($articleCoordinate);
        } catch (\Throwable) {
            // Best-effort only
        }

        return $this->render('pages/_article_highlights_frame.html.twig', [
            'highlights' => $highlights,
        ]);
    }

    #[Route('/p/{npub}/d/{slug}/related', name: 'article-related-frame', requirements: ['slug' => '.+'], priority: 6)]
    public function articleRelatedFrame(
        string $slug,
        string $npub,
        EntityManagerInterface $entityManager,
        ArticleAccessService $articleAccess,
    ): Response {
        $slug = urldecode($slug);
        $article = null;

        try {
            $key = new Key();
            $pubkey = $key->convertToHex($npub);
            $article = $entityManager->getRepository(Article::class)->findOneBy([
                'slug' => $slug,
                'pubkey' => $pubkey,
            ], ['createdAt' => 'DESC']);

            if ($article?->isEssayistExclusive() && !$articleAccess->canViewEssayistExclusive($this->getUser(), $article)) {
                $article = null;
            }
        } catch (\Throwable) {
            $article = null;
        }

        return $this->render('pages/_article_related_frame.html.twig', [
            'article' => $article,
        ]);
    }

    #[Route('/p/{npub}/d/{slug}', name: 'author-article-slug', requirements: ['slug' => '.+'], priority: 5)]
    #[Route('/{vanity}/d/{slug}', name: 'author-vanity-article-slug', requirements: ['slug' => '.+'], priority: 5)]
    public function authorArticle(
        $slug,
        ArticlePageLoader $articlePageLoader,
        LoggerInterface $logger,
        $npub = null,
        $vanity = null
    ): Response
    {
        $resolved = $this->resolveVanityOrRedirect($npub, $vanity, 'author-vanity-article-slug', ['slug' => $slug]);
        if ($resolved instanceof Response) {
            return $resolved;
        }

        $npub = $resolved['npub'];

        try {
            $page = $articlePageLoader->loadPublicArticle($npub, $slug, $this->getUser());
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Invalid author identifier.');
        }

        if ($page->isLoading()) {
            return $this->render('pages/article_loading.html.twig', $page->loadingTemplateParameters());
        }

        if ($page->requiresAccess()) {
            return $this->render('pages/article_essayist_access_required.html.twig');
        }

        try {
            return $this->render('pages/article.html.twig', $page->articleTemplateParameters());
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
