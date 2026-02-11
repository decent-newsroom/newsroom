<?php

declare(strict_types=1);

namespace App\Controller\Newsroom;

use App\Dto\CategoryDraft;
use App\Dto\MagazineDraft;
use App\Enum\KindsEnum;
use App\Form\CategoryArticlesType;
use App\Form\MagazineSetupType;
use App\Message\ProjectMagazineMessage;
use App\Service\Nostr\NostrClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Redis as RedisClient;
use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Magazine Wizard Controller
 *
 * Handles the multi-step creation and editing of magazines.
 * Magazines are hierarchical structures: Magazine -> Categories -> Articles
 * All represented as Nostr kind 30040 events with coordinate references.
 */
class MagazineWizardController extends AbstractController
{
    private const SESSION_KEY = 'mag_wizard';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route('/magazine/wizard/setup', name: 'mag_wizard_setup')]
    public function setup(Request $request, EntityManagerInterface $entityManager): Response
    {
        $draft = $this->getDraft($request);
        if (!$draft) {
            $draft = new MagazineDraft();
            $draft->categories = [new CategoryDraft()];
        }

        $form = $this->createForm(MagazineSetupType::class, $draft);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $draft = $form->getData();
            // Slug generation with a short random suffix
            if (!$draft->slug) {
                $draft->slug = $this->slugifyWithRandom($draft->title);
            }

            // Process categories: either existing lists or new ones
            foreach ($draft->categories as $cat) {
                if ($cat->existingListCoordinate && $cat->existingListCoordinate !== '') {
                    // Load metadata from existing list
                    $this->loadExistingListMetadata($cat, $entityManager);
                } else {
                    // Generate slug for new category
                    if (!$cat->slug) {
                        $cat->slug = $this->slugifyWithRandom($cat->title);
                    }
                }
            }

            $this->saveDraft($request, $draft);
            return $this->redirectToRoute('mag_wizard_articles');
        }

        return $this->render('magazine/magazine_setup.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/magazine/wizard/articles', name: 'mag_wizard_articles')]
    public function articles(Request $request): Response
    {
        $draft = $this->getDraft($request);
        if (!$draft) {
            return $this->redirectToRoute('mag_wizard_setup');
        }

        // Filter out categories that reference existing lists (they already have articles)
        // Use array_values to reindex so form field indices are sequential (0, 1, 2...)
        $editableCategories = array_values(array_filter($draft->categories, fn($cat) => !$cat->isExistingList()));

        // If all categories are existing lists, skip to review
        if (empty($editableCategories)) {
            return $this->redirectToRoute('mag_wizard_review');
        }

        // Build a form as a collection of CategoryArticlesType for editable categories only
        $formBuilder = $this->createFormBuilder(['categories' => $editableCategories]);
        $formBuilder->add('categories', CollectionType::class, [
            'entry_type' => CategoryArticlesType::class,
            'allow_add' => false,
            'allow_delete' => false,
            'by_reference' => false,
            'label' => false,
        ]);
        $form = $formBuilder->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            // Merge the edited categories back into the draft
            $formData = $form->getData();
            $editedCats = $formData['categories'] ?? [];

            // Normalize article coordinates (convert naddr to coordinate format)
            foreach ($editedCats as $cat) {
                if ($cat instanceof CategoryDraft && is_array($cat->articles)) {
                    $normalizedArticles = [];
                    foreach ($cat->articles as $article) {
                        if (is_string($article) && $article !== '') {
                            $normalized = $this->parseNaddr($article);
                            if ($normalized !== null) {
                                $normalizedArticles[] = $normalized;
                            }
                        }
                    }
                    $cat->articles = $normalizedArticles;
                }
            }

            // Replace editable categories in draft with edited versions
            $editedIndex = 0;
            foreach ($draft->categories as $i => $cat) {
                if (!$cat->isExistingList()) {
                    if (isset($editedCats[$editedIndex])) {
                        $draft->categories[$i] = $editedCats[$editedIndex];
                    }
                    $editedIndex++;
                }
            }

            $this->saveDraft($request, $draft);
            return $this->redirectToRoute('mag_wizard_review');
        } elseif ($form->isSubmitted() && !$form->isValid()) {
            // Add flash message for debugging
            $this->addFlash('error', 'Form validation failed. Please check the fields above.');
        }

        return $this->render('magazine/magazine_articles.html.twig', [
            'form' => $form->createView(),
            'hasExistingLists' => count($editableCategories) < count($draft->categories),
        ]);
    }

    #[Route('/magazine/wizard/review', name: 'mag_wizard_review')]
    public function review(Request $request): Response
    {
        $draft = $this->getDraft($request);
        if (!$draft) {
            return $this->redirectToRoute('mag_wizard_setup');
        }

        // Build event skeletons (without pubkey/sig/id); created_at client can adjust
        // Only for NEW categories (not existing lists)
        $categoryEvents = [];
        foreach ($draft->categories as $cat) {
            // Skip existing lists - they don't need new events
            if ($cat->isExistingList()) {
                continue;
            }

            $tags = [];
            $tags[] = ['d', $cat->slug];
            $tags[] = ['type', 'magazine'];
            $tags[] = ['alt', 'This is a publication index event viewable on Decent Newsroom at decentnewsroom.com'];
            if ($cat->title) { $tags[] = ['title', $cat->title]; }
            if ($cat->summary) { $tags[] = ['summary', $cat->summary]; }
            foreach ($cat->tags as $t) { $tags[] = ['t', $t]; }
            foreach ($cat->articles as $a) {
                if (is_string($a) && $a !== '') { $tags[] = ['a', $a]; }
            }
            $categoryEvents[] = [
                'kind' => 30040,
                'created_at' => time(),
                'tags' => $tags,
                'content' => '',
            ];
        }

        // Determine current user's pubkey (hex) from their npub (user identifier)
        $pubkeyHex = null;
        $user = $this->getUser();
        if ($user && method_exists($user, 'getUserIdentifier')) {
            try {
                $key = new Key();
                $pubkeyHex = $key->convertToHex($user->getUserIdentifier());
            } catch (\Throwable $e) {
                $pubkeyHex = null;
            }
        }

        $magTags = [];
        $magTags[] = ['d', $draft->slug];
        $magTags[] = ['type', 'magazine'];
        $magTags[] = ['alt', 'This is a publication index event viewable on Decent Newsroom at decentnewsroom.com'];
        if ($draft->title) { $magTags[] = ['title', $draft->title]; }
        if ($draft->summary) { $magTags[] = ['summary', $draft->summary]; }
        if ($draft->imageUrl) { $magTags[] = ['image', $draft->imageUrl]; }
        if ($draft->language) { $magTags[] = ['l', $draft->language]; }
        foreach ($draft->tags as $t) { $magTags[] = ['t', $t]; }

        // Add category coordinates as 'a' tags
        if ($pubkeyHex) {
            foreach ($draft->categories as $cat) {
                if ($cat->isExistingList()) {
                    // Use the existing coordinate directly
                    $magTags[] = ['a', $cat->existingListCoordinate];
                } elseif ($cat->slug) {
                    // Build coordinate for new category
                    $magTags[] = ['a', sprintf('30040:%s:%s', $pubkeyHex, $cat->slug)];
                }
            }
        }

        $magazineEvent = [
            'kind' => 30040,
            'created_at' => time(),
            'tags' => $magTags,
            'content' => '',
        ];

        return $this->render('magazine/magazine_review.html.twig', [
            'draft' => $draft,
            'categoryEventsJson' => json_encode($categoryEvents, JSON_UNESCAPED_SLASHES),
            'magazineEventJson' => json_encode($magazineEvent, JSON_UNESCAPED_SLASHES),
            'csrfToken' => $this->container->get('security.csrf.token_manager')->getToken('nostr_publish')->getValue(),
        ]);
    }

    #[Route('/api/index/publish', name: 'api-index-publish', methods: ['POST'])]
    public function publishIndexEvent(
        Request                   $request,
        CacheItemPoolInterface    $appCache,
        RedisClient               $redis,
        EntityManagerInterface    $entityManager,
        NostrClient               $nostrClient,
        LoggerInterface           $logger
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        if (!$data || !isset($data['event'])) {
            return new JsonResponse(['error' => 'Invalid request'], 400);
        }

        $signedEvent = $data['event'];

        // Convert array to swentel Event and verify
        $eventObj = new Event();
        $eventObj->setId($signedEvent['id'] ?? '');
        $eventObj->setPublicKey($signedEvent['pubkey'] ?? '');
        $eventObj->setCreatedAt($signedEvent['created_at'] ?? time());
        $eventObj->setKind($signedEvent['kind'] ?? KindsEnum::PUBLICATION_INDEX->value);
        $eventObj->setTags($signedEvent['tags'] ?? []);
        $eventObj->setContent($signedEvent['content'] ?? '');
        $eventObj->setSignature($signedEvent['sig'] ?? '');

        if (!$eventObj->verify()) {
            return new JsonResponse(['error' => 'Verification failed'], 400);
        }

        // Extract slug from 'd' tag
        $slug = null;
        foreach ($signedEvent['tags'] as $tag) {
            if (isset($tag[0]) && $tag[0] === 'd' && isset($tag[1])) {
                $slug = $tag[1];
                break;
            }
        }
        if (!$slug) {
            return new JsonResponse(['error' => 'Missing d tag/slug'], 400);
        }

        // Save to Redis under magazine-<slug>
        try {
            $key = 'magazine-' . $slug;
            $item = $appCache->getItem($key);
            $item->set($eventObj);
            $appCache->save($item);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Redis error'], 500);
        }

        // If the event is a top-level magazine index (references 30040 categories), record slug in a set for admin listing
        $isTopLevelMagazine = false;
        try {
            foreach ($signedEvent['tags'] as $tag) {
                if (($tag[0] ?? null) === 'a' && isset($tag[1]) && str_starts_with((string)$tag[1], '30040:')) {
                    $isTopLevelMagazine = true;
                    break;
                }
            }
            if ($isTopLevelMagazine) {
                $redis->sAdd('magazine_slugs', $slug);
            }
        } catch (\Throwable $e) {
            // non-fatal
        }

        // Save to persistence as Event entity
        $event = new \App\Entity\Event();
        $event->setId($eventObj->getId());
        $event->setPubkey($eventObj->getPublicKey());
        $event->setCreatedAt($eventObj->getCreatedAt());
        $event->setKind($eventObj->getKind());
        $event->setTags($eventObj->getTags());
        $event->setContent($eventObj->getContent());
        $event->setSig($eventObj->getSignature());
        $entityManager->persist($event);
        $entityManager->flush();

        // Publish to author's relays (passing empty array lets NostrClient fetch author's relay list)
        $relayResults = [];
        try {
            $logger->info('Publishing magazine event to relays', [
                'event_id' => $eventObj->getId(),
                'slug' => $slug,
            ]);

            // Empty array = NostrClient will fetch author's preferred relays
            $relayResults = $nostrClient->publishEvent($eventObj, []);

            $logger->info('Magazine event published to relays', [
                'event_id' => $eventObj->getId(),
                'results' => $relayResults,
            ]);
        } catch (\Throwable $e) {
            $logger->warning('Failed to publish magazine event to relays', [
                'event_id' => $eventObj->getId(),
                'error' => $e->getMessage(),
            ]);
            // Non-fatal: event is saved locally, relay publishing is best-effort
        }

        // Dispatch projection message for top-level magazines
        if ($isTopLevelMagazine) {
            try {
                $this->messageBus->dispatch(new ProjectMagazineMessage($slug));
            } catch (\Throwable $e) {
                // Non-fatal: projection will be picked up by cron if async dispatch fails
            }
        }

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/magazine/wizard/cancel', name: 'mag_wizard_cancel', methods: ['GET'])]
    public function cancel(Request $request): Response
    {
        $this->clearDraft($request);
        $this->addFlash('info', 'Magazine setup canceled.');
        return $this->redirectToRoute('home');
    }

    #[Route('/magazine/wizard/edit/{slug}', name: 'mag_wizard_edit')]
    public function editStart(string $slug, EntityManagerInterface $entityManager, Request $request): Response
    {
        // Load magazine event from database
        $sql = "SELECT e.* FROM event e
                WHERE e.tags::jsonb @> ?::jsonb
                LIMIT 1";

        $conn = $entityManager->getConnection();
        $result = $conn->executeQuery($sql, [
            json_encode([['d', $slug]])
        ]);

        $magEventData = $result->fetchAssociative();

        if ($magEventData === false) {
            throw $this->createNotFoundException('Magazine not found');
        }

        $tags = json_decode($magEventData['tags'], true);

        $draft = new MagazineDraft();
        $draft->slug = $slug;
        $draft->title = $this->getTagValue($tags, 'title') ?? '';
        $draft->summary = $this->getTagValue($tags, 'summary') ?? '';
        $draft->imageUrl = $this->getTagValue($tags, 'image') ?? '';
        $draft->language = $this->getTagValue($tags, 'l');
        $draft->tags = $this->getAllTagValues($tags, 't');
        $draft->categories = [];

        $magazinePubkey = $magEventData['pubkey'];

        // For each category coordinate (30040:pubkey:slug), load its index and map
        foreach ($tags as $t) {
            if (is_array($t) && ($t[0] ?? null) === 'a' && isset($t[1]) && str_starts_with((string)$t[1], '30040:')) {
                $coordinate = (string)$t[1];
                $parts = explode(':', $coordinate, 3);
                if (count($parts) !== 3) { continue; }
                [$kind, $catPubkey, $catSlug] = $parts;

                // Query database for category event
                $catResult = $conn->executeQuery($sql, [
                    json_encode([['d', $catSlug]])
                ]);
                $catEventData = $catResult->fetchAssociative();

                if ($catEventData === false) { continue; }

                $ctags = json_decode($catEventData['tags'], true);
                $cat = new CategoryDraft();
                $cat->slug = $catSlug;
                $cat->title = $this->getTagValue($ctags, 'title') ?? '';
                $cat->summary = $this->getTagValue($ctags, 'summary') ?? '';
                $cat->tags = $this->getAllTagValues($ctags, 't');

                // If category pubkey differs from magazine pubkey, it's an existing list reference
                if ($catPubkey !== $magazinePubkey) {
                    $cat->existingListCoordinate = $coordinate;
                }

                $cat->articles = [];
                foreach ($ctags as $ct) {
                    if (is_array($ct) && ($ct[0] ?? null) === 'a' && isset($ct[1])) {
                        $cat->articles[] = (string)$ct[1];
                    }
                }
                $draft->categories[] = $cat;
            }
        }

        $this->saveDraft($request, $draft);
        return $this->redirectToRoute('mag_wizard_setup');
    }

    private function getTagValue(array $tags, string $name): ?string
    {
        foreach ($tags as $t) {
            if (is_array($t) && ($t[0] ?? null) === $name && isset($t[1])) {
                return (string)$t[1];
            }
        }
        return null;
    }

    private function getAllTagValues(array $tags, string $name): array
    {
        $out = [];
        foreach ($tags as $t) {
            if (is_array($t) && ($t[0] ?? null) === $name && isset($t[1])) {
                $out[] = (string)$t[1];
            }
        }
        return $out;
    }

    private function getDraft(Request $request): ?MagazineDraft
    {
        $data = $request->getSession()->get(self::SESSION_KEY);
        return $data instanceof MagazineDraft ? $data : null;
    }

    private function saveDraft(Request $request, MagazineDraft $draft): void
    {
        $request->getSession()->set(self::SESSION_KEY, $draft);
    }

    private function clearDraft(Request $request): void
    {
        $request->getSession()->remove(self::SESSION_KEY);
    }

    private function slugifyWithRandom(string $title): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)) ?? '');
        $slug = trim(preg_replace('/-+/', '-', $slug) ?? '', '-');
        $rand = substr(bin2hex(random_bytes(4)), 0, 6);
        return $slug !== '' ? ($slug . '-' . $rand) : $rand;
    }

    /**
     * Convert naddr to coordinate format (kind:pubkey:identifier)
     * If already a coordinate, returns as-is
     *
     * @param string $input Either naddr or coordinate
     * @return string|null Coordinate string or null if invalid
     */
    private function parseNaddr(string $input): ?string
    {
        $input = trim($input);

        if (empty($input)) {
            return null;
        }

        // If already a coordinate format (kind:pubkey:slug), return as-is
        if (preg_match('/^\d+:[a-f0-9]{64}:.+$/i', $input)) {
            return $input;
        }

        // Try to decode as naddr
        if (str_starts_with($input, 'naddr1')) {
            try {
                $helper = new \swentel\nostr\Nip19\Nip19Helper();
                $decoded = $helper->decode($input);

                // The library returns 'author' (not 'pubkey') for naddr
                if (!isset($decoded['kind'], $decoded['author'], $decoded['identifier'])) {
                    return null;
                }

                return sprintf('%d:%s:%s', $decoded['kind'], $decoded['author'], $decoded['identifier']);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * Load metadata from an existing list event by coordinate
     */
    private function loadExistingListMetadata(CategoryDraft $cat, EntityManagerInterface $entityManager): void
    {
        if (!$cat->existingListCoordinate) {
            return;
        }

        // Parse coordinate: 30040:pubkey:slug
        $parts = explode(':', $cat->existingListCoordinate, 3);
        if (count($parts) !== 3) {
            return; // Invalid coordinate format
        }

        [, $pubkey, $slug] = $parts;
        $cat->slug = $slug;

        // Query database for the list event
        $sql = "SELECT e.* FROM event e
                WHERE e.tags::jsonb @> ?::jsonb
                AND e.pubkey = ?
                AND e.kind = 30040
                ORDER BY e.created_at DESC
                LIMIT 1";

        try {
            $conn = $entityManager->getConnection();
            $result = $conn->executeQuery($sql, [
                json_encode([['d', $slug]]),
                $pubkey
            ]);

            $eventData = $result->fetchAssociative();

            if ($eventData !== false) {
                $tags = json_decode($eventData['tags'], true);

                // Load metadata from existing event
                $cat->title = $this->getTagValue($tags, 'title') ?? $cat->title;
                $cat->summary = $this->getTagValue($tags, 'summary') ?? '';
                $cat->image = $this->getTagValue($tags, 'image') ?? '';
                $cat->tags = $this->getAllTagValues($tags, 't');

                // Load article coordinates
                $cat->articles = [];
                foreach ($tags as $t) {
                    if (is_array($t) && ($t[0] ?? null) === 'a' && isset($t[1])) {
                        $cat->articles[] = (string)$t[1];
                    }
                }
            }
        } catch (\Throwable $e) {
            // If we can't load the list, just keep the coordinate
            // The user can still proceed and the coordinate will be used as-is
        }
    }
}
