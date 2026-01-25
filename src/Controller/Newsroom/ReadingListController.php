<?php

declare(strict_types=1);

namespace App\Controller\Newsroom;

use App\Dto\CategoryDraft;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Form\CategoryArticlesType;
use App\Form\CategoryType;
use App\Service\ReadingListManager;
use Doctrine\ORM\EntityManagerInterface;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Reading List Controller
 *
 * Handles creation, editing and management of reading lists (standalone) and categories (for magazines).
 * Both use Nostr kind 30040 events with different 'type' tags.
 */
class ReadingListController extends AbstractController
{
    private const SESSION_KEY = 'read_wizard';

    // ─────────────────────────────────────────────────────────────────────────
    // Index & Compose Routes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Display the user's reading lists.
     */
    #[Route('/reading-list', name: 'reading_list_index')]
    public function index(EntityManagerInterface $em): Response
    {
        $lists = [];
        $user = $this->getUser();
        $pubkeyHex = null;
        if ($user) {
            try {
                $key = new Key();
                $pubkeyHex = $key->convertToHex($user->getUserIdentifier());
            } catch (\Throwable $e) {
                $pubkeyHex = null;
            }
        }

        if ($pubkeyHex) {
            $repo = $em->getRepository(Event::class);
            $events = $repo->findBy(['kind' => KindsEnum::PUBLICATION_INDEX->value, 'pubkey' => $pubkeyHex], ['created_at' => 'DESC']);
            $seenSlugs = [];
            foreach ($events as $ev) {
                if (!$ev instanceof Event) continue;
                $tags = $ev->getTags();
                $typeTag = null;
                $hasLongFormArticles = false;
                $hasAnyATags = false;
                $hasMagazineReferences = false;
                $title = null; $slug = null; $summary = null;
                $articleCount = 0;

                foreach ($tags as $t) {
                    if (is_array($t)) {
                        if (($t[0] ?? null) === 'type') { $typeTag = $t[1] ?? null; }
                        if (($t[0] ?? null) === 'title') { $title = (string)($t[1] ?? ''); }
                        if (($t[0] ?? null) === 'summary') { $summary = (string)($t[1] ?? ''); }
                        if (($t[0] ?? null) === 'd') { $slug = (string)($t[1] ?? ''); }

                        // Check for any 'a' tags
                        if (($t[0] ?? null) === 'a' && isset($t[1])) {
                            $hasAnyATags = true;
                            // Check if this references long-form articles (kind 30023)
                            if (str_starts_with((string)$t[1], '30023:')) {
                                $hasLongFormArticles = true;
                                $articleCount++;
                            }
                            // Check if this references other 30040 events (magazine index)
                            if (str_starts_with((string)$t[1], '30040:')) {
                                $hasMagazineReferences = true;
                            }
                        }
                    }
                }

                // Include:
                // 1. Events that reference long-form articles (30023) - active reading lists
                // 2. Events with NO 'a' tags at all - empty/draft reading lists to be edited
                // Exclude:
                // - Events that reference 30040 (magazine indexes)
                $isReadingList = !$hasMagazineReferences && ($hasLongFormArticles || !$hasAnyATags);

                if ($isReadingList) {
                    // Collapse by slug: keep only newest per slug
                    $keySlug = $slug ?: ('__no_slug__:' . $ev->getId());
                    if (isset($seenSlugs[$slug ?? $keySlug])) {
                        continue;
                    }
                    $seenSlugs[$slug ?? $keySlug] = true;

                    $lists[] = [
                        'id' => $ev->getId(),
                        'title' => $title ?: '(untitled)',
                        'summary' => $summary,
                        'slug' => $slug,
                        'createdAt' => $ev->getCreatedAt(),
                        'pubkey' => $ev->getPubkey(),
                        'type' => $typeTag, // Keep for display as badge
                        'articleCount' => $articleCount,
                        'isEmpty' => !$hasAnyATags, // Flag for empty lists
                    ];
                }
            }
        }

        return $this->render('reading_list/index.html.twig', [
            'lists' => $lists,
        ]);
    }

    #[Route('/reading-list/compose', name: 'reading_list_compose')]
    public function compose(Request $request): Response
    {
        // Check if a coordinate was passed via URL parameter
        $coordinate = $request->query->get('add');
        $addedArticle = null;

        if ($coordinate) {
            // Auto-add the coordinate to the current draft
            $session = $request->getSession();
            $draft = $session->get(self::SESSION_KEY);

            if (!$draft instanceof CategoryDraft) {
                $draft = new CategoryDraft();
                $draft->title = 'My Reading List';
                $draft->slug = substr(bin2hex(random_bytes(6)), 0, 8);
            }

            if (!in_array($coordinate, $draft->articles, true)) {
                $draft->articles[] = $coordinate;
                $session->set(self::SESSION_KEY, $draft);
                $addedArticle = $coordinate;
            }
        }

        return $this->render('reading_list/compose.html.twig', [
            'addedArticle' => $addedArticle,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Wizard Routes
    // ─────────────────────────────────────────────────────────────────────────

    #[Route('/reading-list/wizard/setup', name: 'read_wizard_setup')]
    public function setup(Request $request): Response
    {
        $draft = $this->getDraft($request) ?? new CategoryDraft();

        // Check if this is being created as a magazine category
        $asMagazineCategory = $request->query->getBoolean('category', false);
        if ($asMagazineCategory) {
            $request->getSession()->set('read_wizard_type', 'category');
        }

        $form = $this->createForm(CategoryType::class, $draft);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var CategoryDraft $draft */
            $draft = $form->getData();

            // Transform naddr to coordinate if needed
            if ($draft->existingListCoordinate && str_starts_with($draft->existingListCoordinate, 'naddr1')) {
                $coordinate = $this->parseNaddr($draft->existingListCoordinate);
                if ($coordinate) {
                    $draft->existingListCoordinate = $coordinate;
                }
            }

            if (!$draft->slug) {
                $draft->slug = $this->slugifyWithRandom($draft->title);
            }
            $this->saveDraft($request, $draft);
            return $this->redirectToRoute('read_wizard_articles');
        }

        return $this->render('reading_list/reading_setup.html.twig', [
            'form' => $form->createView(),
            'isMagazineCategory' => $request->getSession()->get('read_wizard_type') === 'category',
        ]);
    }

    #[Route('/reading-list/wizard/articles', name: 'read_wizard_articles')]
    public function articles(Request $request, ReadingListManager $readingListManager): Response
    {
        $draft = $this->getDraft($request);

        $loadSlug = $request->query->get('load');
        if ($loadSlug) {
            $draft = $readingListManager->loadPublishedListIntoDraft($loadSlug);
            $this->saveDraft($request, $draft);
        } elseif (!$draft) {
            return $this->redirectToRoute('read_wizard_setup');
        }

        // Ensure at least one input is visible initially
        if (empty($draft->articles)) {
            $draft->articles = [''];
        }

        $form = $this->createForm(CategoryArticlesType::class, $draft);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            /** @var CategoryDraft $draft */
            $draft = $form->getData();

            // Transform any naddr values to coordinates in the articles array
            if (!empty($draft->articles)) {
                $draft->articles = array_map(function($article) {
                    if (is_string($article) && str_starts_with($article, 'naddr1')) {
                        $coordinate = $this->parseNaddr($article);
                        return $coordinate ?? $article;
                    }
                    return $article;
                }, $draft->articles);
            }

            // ensure slug exists
            if (!$draft->slug) {
                $draft->slug = $this->slugifyWithRandom($draft->title);
            }
            // If draft articles is still empty, remove the empty string we added
            if (count($draft->articles) === 1 && $draft->articles[0] === '') {
                $draft->articles = [];
            }

            $this->saveDraft($request, $draft);
            return $this->redirectToRoute('read_wizard_review');
        }

        return $this->render('reading_list/reading_articles.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/reading-list/add-article', name: 'read_wizard_add_article')]
    public function addArticle(Request $request, ReadingListManager $readingListManager): Response
    {
        // Get the coordinate from the query parameter (supports both coordinate and naddr)
        $coordinate = $request->query->get('coordinate');
        $naddr = $request->query->get('naddr');

        // Parse naddr if provided
        if ($naddr && !$coordinate) {
            $coordinate = $this->parseNaddr($naddr);
        }

        if (!$coordinate) {
            $this->addFlash('error', 'No article coordinate provided.');
            return $this->redirectToRoute('reading_list_compose');
        }

        // Validate coordinate format (kind:pubkey:slug)
        if (!$this->isValidCoordinate($coordinate)) {
            $this->addFlash('error', 'Invalid coordinate format. Expected: kind:pubkey:slug');
            return $this->redirectToRoute('reading_list_compose');
        }

        // Get available reading lists
        $availableLists = $readingListManager->getUserReadingLists();
        $currentDraft = $readingListManager->getCurrentDraft();

        // Handle form submission
        if ($request->isMethod('POST')) {
            $selectedSlug = $request->request->get('selected_list');

            // Load or create the selected list
            if ($selectedSlug === '__new__' || !$selectedSlug) {
                $draft = $readingListManager->createNewDraft();
            } else {
                $draft = $readingListManager->loadPublishedListIntoDraft($selectedSlug);
            }

            // Add the article to the draft
            if ($draft && !in_array($coordinate, $draft->articles, true)) {
                $draft->articles[] = $coordinate;
                $session = $request->getSession();
                $session->set(self::SESSION_KEY, $draft);
            }

            // Redirect to compose page with success message
            return $this->redirectToRoute('reading_list_compose', [
                'add' => $coordinate,
                'list' => $selectedSlug ?? '__new__'
            ]);
        }

        return $this->render('reading_list/add_article_confirm.html.twig', [
            'coordinate' => $coordinate,
            'parsedCoordinate' => $this->parseCoordinate($coordinate),
            'availableLists' => $availableLists,
            'currentDraft' => $currentDraft,
        ]);
    }

    #[Route('/reading-list/wizard/review', name: 'read_wizard_review')]
    public function review(Request $request): Response
    {
        $draft = $this->getDraft($request);
        if (!$draft) {
            return $this->redirectToRoute('read_wizard_setup');
        }

        // Transform any remaining naddr values to coordinates (safety check)
        if (!empty($draft->articles)) {
            $transformed = false;
            $draft->articles = array_map(function($article) use (&$transformed) {
                if (is_string($article) && str_starts_with($article, 'naddr1')) {
                    $coordinate = $this->parseNaddr($article);
                    if ($coordinate) {
                        $transformed = true;
                        return $coordinate;
                    }
                }
                return $article;
            }, $draft->articles);

            // If we transformed any naddr values, save the updated draft
            if ($transformed) {
                $this->saveDraft($request, $draft);
            }
        }

        // Transform existingListCoordinate if it's an naddr (safety check)
        if ($draft->existingListCoordinate && str_starts_with($draft->existingListCoordinate, 'naddr1')) {
            $coordinate = $this->parseNaddr($draft->existingListCoordinate);
            if ($coordinate) {
                $draft->existingListCoordinate = $coordinate;
                $this->saveDraft($request, $draft);
            }
        }

        // Determine the type based on session flag
        $type = $request->getSession()->get('read_wizard_type', 'reading-list');

        // Build a single category event skeleton
        $tags = [];
        $tags[] = ['d', $draft->slug];
        $tags[] = ['type', $type];
        if ($draft->title) { $tags[] = ['title', $draft->title]; }
        if ($draft->summary) { $tags[] = ['summary', $draft->summary]; }
        if ($draft->image) { $tags[] = ['image', $draft->image]; }
        foreach ($draft->tags as $t) { $tags[] = ['t', $t]; }
        foreach ($draft->articles as $a) {
            if (is_string($a) && $a !== '') { $tags[] = ['a', $a]; }
        }

        $event = [
            'kind' => 30040,
            'created_at' => time(),
            'tags' => $tags,
            'content' => '',
        ];

        return $this->render('reading_list/reading_review.html.twig', [
            'draft' => $draft,
            'eventJson' => json_encode($event, JSON_UNESCAPED_SLASHES),
            'csrfToken' => $this->container->get('security.csrf.token_manager')->getToken('nostr_publish')->getValue(),
            'isMagazineCategory' => $type === 'category',
        ]);
    }

    #[Route('/reading-list/wizard/cancel', name: 'read_wizard_cancel', methods: ['GET'])]
    public function cancel(Request $request): Response
    {
        $this->clearDraft($request);
        $this->addFlash('info', 'Reading list creation canceled.');
        return $this->redirectToRoute('home');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Parse an naddr (NIP-19) into a coordinate string
     * naddr encodes: kind, pubkey, d-tag (slug), and optional relays
     */
    private function parseNaddr(string $naddr): ?string
    {
        try {
            // Remove naddr1 prefix if present and decode bech32
            if (!str_starts_with($naddr, 'naddr1')) {
                return null;
            }

            // Use the nostr library to decode naddr
            $helper = new \swentel\nostr\Nip19\Nip19Helper();
            $decoded = $helper->decode($naddr);

            if (!isset($decoded['kind'], $decoded['pubkey'], $decoded['identifier'])) {
                return null;
            }

            return sprintf('%d:%s:%s', $decoded['kind'], $decoded['pubkey'], $decoded['identifier']);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Parse a coordinate string into its components
     *
     * @return array{kind: int, pubkey: string, slug: string}|null
     */
    private function parseCoordinate(string $coordinate): ?array
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3) {
            return null;
        }

        return [
            'kind' => (int)$parts[0],
            'pubkey' => $parts[1],
            'slug' => $parts[2],
        ];
    }

    /**
     * Validate coordinate format
     */
    private function isValidCoordinate(string $coordinate): bool
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3) {
            return false;
        }

        // Kind should be numeric
        if (!is_numeric($parts[0])) {
            return false;
        }

        // Pubkey should be 64 hex characters
        if (!preg_match('/^[a-f0-9]{64}$/i', $parts[1])) {
            return false;
        }

        // Slug should not be empty
        if (empty($parts[2])) {
            return false;
        }

        return true;
    }

    private function getDraft(Request $request): ?CategoryDraft
    {
        $data = $request->getSession()->get(self::SESSION_KEY);
        if (!$data instanceof CategoryDraft) {
            return null;
        }

        // Transform any naddr values to coordinates when loading from session
        $needsSave = false;

        if (!empty($data->articles)) {
            $originalArticles = $data->articles;
            $data->articles = array_map(function($article) {
                if (is_string($article) && str_starts_with($article, 'naddr1')) {
                    $coordinate = $this->parseNaddr($article);
                    return $coordinate ?? $article;
                }
                return $article;
            }, $data->articles);

            if ($originalArticles !== $data->articles) {
                $needsSave = true;
            }
        }

        if ($data->existingListCoordinate && str_starts_with($data->existingListCoordinate, 'naddr1')) {
            $coordinate = $this->parseNaddr($data->existingListCoordinate);
            if ($coordinate) {
                $data->existingListCoordinate = $coordinate;
                $needsSave = true;
            }
        }

        // Save the transformed draft back to session if we made changes
        if ($needsSave) {
            $this->saveDraft($request, $data);
        }

        return $data;
    }

    private function saveDraft(Request $request, CategoryDraft $draft): void
    {
        $request->getSession()->set(self::SESSION_KEY, $draft);
    }

    private function clearDraft(Request $request): void
    {
        $request->getSession()->remove(self::SESSION_KEY);
        $request->getSession()->remove('read_wizard_type');
    }

    private function slugifyWithRandom(string $title): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)) ?? '');
        $slug = trim(preg_replace('/-+/', '-', $slug) ?? '', '-');
        $rand = substr(bin2hex(random_bytes(4)), 0, 6);
        return $slug !== '' ? ($slug . '-' . $rand) : $rand;
    }
}
