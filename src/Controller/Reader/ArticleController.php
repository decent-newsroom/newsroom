<?php

namespace App\Controller\Reader;

use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Service\Cache\RedisCacheService;
use App\Service\HighlightService;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrEventParser;
use App\Service\VanityNameService;
use App\Util\CommonMark\Converter;
use Doctrine\ORM\EntityManagerInterface;
use League\CommonMark\Exception\CommonMarkException;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;
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
    #[Route('/article/{naddr}', name: 'article-naddr', requirements: ['naddr' => '^(naddr1[0-9a-zA-Z]+)$'])]
    public function naddr(NostrClient $nostrClient, EntityManagerInterface $em, $naddr)
    {
        set_time_limit(120); // 2 minutes
        $decoded = new Bech32($naddr);

        if ($decoded->type !== 'naddr') {
            return $this->render('pages/article_not_found.html.twig', [
                'message' => 'Invalid Nostr address (naddr). Please check the address and try again.',
                'searchQuery' => $naddr
            ]);
        }

        /** @var NAddr $data */
        $data = $decoded->data;
        $slug = $data->identifier;
        $relays = $data->relays;
        $author = $data->pubkey;
        $kind = $data->kind;

        if ($kind !== KindsEnum::LONGFORM->value) {
            return $this->render('pages/article_not_found.html.twig', [
                'message' => 'This is not a long-form article. Only long-form articles (kind 30023) are supported.',
                'searchQuery' => $naddr
            ]);
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
            return $this->render('pages/article_not_found.html.twig', [
                'message' => sprintf(
                    'No article found for slug "%s" by author %s. The article may not exist or the relays may be offline.',
                    $slug,
                    substr($author, 0, 8) . '...'
                ),
                'searchQuery' => $naddr
            ]);
        }

        return $this->render('pages/article_not_found.html.twig', [
            'message' => 'Article was retrieved from relays but could not be saved to the database.',
            'searchQuery' => $naddr
        ]);
    }


    #[Route('/article/d/{slug}/draft', name: 'draft-slug', requirements: ['slug' => '.+'])]
    public function draftSlug($slug, EntityManagerInterface $entityManager): Response
    {
        // Drafts require authentication
        if (!$this->getUser()) {
            throw $this->createAccessDeniedException('You must be logged in to view drafts.');
        }

        $slug = urldecode($slug);
        $key = new Key();
        $currentPubkey = $key->convertToHex($this->getUser()->getUserIdentifier());

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
        $npub = $key->convertPublicKeyToBech32($currentPubkey);
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
    #[Route('/article/d/{slug}', name: 'article-slug', requirements: ['slug' => '.+'])]
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

        set_time_limit(300);
        ini_set('max_execution_time', '300');
        $slug = urldecode($slug);
        $key = new Key();
        $pubkey = $key->convertToHex($npub);

        // Verify the user is the author of this draft
        $currentPubkey = $key->convertToHex($this->getUser()->getUserIdentifier());
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
            // Fall back to converting on-the-fly and save for future requests
            $htmlContent = $converter->convertToHTML($draft->getContent());
            $draft->setProcessedHtml($htmlContent);
            $entityManager->flush();
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
        } catch (\Exception $e) {}

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
        $npub = null,
        $vanity = null
    ): Response
    {
        $resolved = $this->resolveVanityOrRedirect($npub, $vanity, 'author-vanity-article-slug', ['slug' => $slug]);
        if ($resolved instanceof Response) {
            return $resolved;
        }

        $npub = $resolved['npub'];

        set_time_limit(300);
        ini_set('max_execution_time', '300');
        $slug = urldecode($slug);
        $key = new Key();
        $pubkey = $key->convertToHex($npub);
        $repository = $entityManager->getRepository(Article::class);
        $article = $repository->findOneBy(['slug' => $slug, 'pubkey' => $pubkey], ['createdAt' => 'DESC']);
        if (!$article) {
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
                // Fall back to converting on-the-fly and save for future requests
                $htmlContent = $converter->convertToHTML($article->getContent());
                $article->setProcessedHtml($htmlContent);
                $entityManager->flush();
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

}
