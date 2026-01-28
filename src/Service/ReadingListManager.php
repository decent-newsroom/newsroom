<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CategoryDraft;
use App\Entity\Event;
use Doctrine\ORM\EntityManagerInterface;
use swentel\nostr\Key\Key;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use function Webmozart\Assert\Tests\StaticAnalysis\startsWith;

/**
 * Service for managing reading list drafts and published lists
 */
class ReadingListManager
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly RequestStack $requestStack,
        private readonly ReadingListWorkflowService $workflowService,
    ) {}

    /**
     * Get all published reading lists and categories for the current user
     * Includes reading lists (30040) and curation sets (30004, 30005, 30006)
     * @return array<array{id: int, title: string, summary: ?string, slug: string, createdAt: \DateTimeInterface, pubkey: string, itemCount: int, kind: int, type: string, isEmpty: bool}>
     */
    public function getUserReadingLists(): array
    {
        $lists = [];
        $user = $this->tokenStorage->getToken()?->getUser();

        if (!$user) {
            return [];
        }

        try {
            $key = new Key();
            $pubkeyHex = $key->convertToHex($user->getUserIdentifier());
        } catch (\Throwable $e) {
            return [];
        }

        $repo = $this->em->getRepository(Event::class);
        // Query for reading lists (30040) and all curation set types (30004, 30005, 30006)
        $events = $repo->createQueryBuilder('e')
            ->where('e.kind IN (:kinds)')
            ->andWhere('e.pubkey = :pubkey')
            ->setParameter('kinds', [30040, 30004, 30005, 30006])
            ->setParameter('pubkey', $pubkeyHex)
            ->orderBy('e.created_at', 'DESC')
            ->getQuery()
            ->getResult();

        $seenSlugs = [];

        foreach ($events as $ev) {
            if (!$ev instanceof Event) continue;
            $tags = $ev->getTags();
            $kind = $ev->getKind();
            $title = null;
            $slug = null;
            $summary = null;
            $itemCount = 0;
            $hasAnyATags = false;
            $hasAnyETags = false;

            // Determine type label from kind
            $typeLabel = match($kind) {
                30040 => 'Reading List',
                30004 => 'Articles/Notes',
                30005 => 'Videos',
                30006 => 'Pictures',
                default => 'Unknown',
            };

            foreach ($tags as $t) {
                if (is_array($t)) {
                    if (($t[0] ?? null) === 'title') {
                        $title = (string)$t[1];
                    }
                    if (($t[0] ?? null) === 'summary') {
                        $summary = (string)$t[1];
                    }
                    if (($t[0] ?? null) === 'd') {
                        $slug = (string)$t[1];
                    }
                    // Count all 'a' tags (coordinate references)
                    if (($t[0] ?? null) === 'a' && isset($t[1])) {
                        $hasAnyATags = true;
                        $itemCount++;
                    }
                    // Count all 'e' tags (event ID references)
                    if (($t[0] ?? null) === 'e' && isset($t[1])) {
                        $hasAnyETags = true;
                        $itemCount++;
                    }
                }
            }

            // For kind 30040, check for magazine index and skip
            if ($kind === 30040) {
                $isIndex = false;
                $isMagazine = false;

                foreach ($tags as $t) {
                    if (is_array($t)) {
                        if (($t[0] ?? null) === 'type' && in_array($t[1] ?? null, ['reading-list', 'magazine'])) {
                            $isIndex = true;
                        }
                        if (($t[0] ?? null) === 'a' && isset($t[1]) && str_starts_with($t[1], '30040:')) {
                            $isMagazine = true;
                        }
                    }
                }

                // Skip magazine indexes (30040 events that reference other 30040 events)
                if ($isMagazine) {
                    continue;
                }
            }

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
                'itemCount' => $itemCount,
                'kind' => $kind,
                'type' => $typeLabel,
                'isEmpty' => !$hasAnyATags && !$hasAnyETags,
            ];
        }

        return $lists;
    }

    /**
     * Get the current draft reading list from session
     */
    public function getCurrentDraft(): ?CategoryDraft
    {
        $session = $this->requestStack->getSession();
        $data = $session->get('read_wizard');
        return $data instanceof CategoryDraft ? $data : null;
    }

    /**
     * Get the currently selected reading list slug (or null for new draft)
     */
    public function getSelectedListSlug(): ?string
    {
        $session = $this->requestStack->getSession();
        return $session->get('selected_reading_list_slug');
    }

    /**
     * Set which reading list is currently selected
     */
    public function setSelectedListSlug(?string $slug): void
    {
        $session = $this->requestStack->getSession();
        if ($slug === null) {
            $session->remove('selected_reading_list_slug');
        } else {
            $session->set('selected_reading_list_slug', $slug);
        }
    }

    /**
     * Load an existing published reading list into the draft
     */
    public function loadPublishedListIntoDraft(string $slug): ?CategoryDraft
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user) {
            return null;
        }

        try {
            $key = new Key();
            $pubkeyHex = $key->convertToHex($user->getUserIdentifier());
        } catch (\Throwable $e) {
            return null;
        }

        $repo = $this->em->getRepository(Event::class);
        $events = $repo->findBy(['kind' => 30040, 'pubkey' => $pubkeyHex], ['created_at' => 'DESC']);

        foreach ($events as $ev) {
            if (!$ev instanceof Event) continue;
            $tags = $ev->getTags();
            $isReadingList = false;
            $eventSlug = null;

            // First pass: check if this is the right event
            foreach ($tags as $t) {
                if (is_array($t)) {
                    if (($t[0] ?? null) === 'd') {
                        $eventSlug = (string)$t[1];
                    }
                    if (($t[0] ?? null) === 'type' && in_array($t[1] ?? null, ['reading-list', 'category'])) {
                        $isReadingList = true;
                    }
                }
            }

            if ($isReadingList && $eventSlug === $slug) {
                // Found it! Parse into CategoryDraft
                $draft = new CategoryDraft();
                $draft->slug = $slug;

                foreach ($tags as $t) {
                    if (!is_array($t)) continue;
                    $tagName = $t[0] ?? null;
                    $tagValue = $t[1] ?? null;

                    match ($tagName) {
                        'title' => $draft->title = (string)$tagValue,
                        'summary' => $draft->summary = (string)$tagValue,
                        'image' => $draft->image = (string)$tagValue,
                        't' => $draft->tags[] = (string)$tagValue,
                        'a' => $draft->articles[] = (string)$tagValue,
                        default => null,
                    };
                }

                // Save to session
                $session = $this->requestStack->getSession();
                $session->set('read_wizard', $draft);
                $this->setSelectedListSlug($slug);

                return $draft;
            }
        }

        return null;
    }

    /**
     * Create a new draft reading list
     */
    public function createNewDraft(): CategoryDraft
    {
        $draft = new CategoryDraft();
        $draft->title = 'My Reading List';
        $draft->slug = substr(bin2hex(random_bytes(6)), 0, 8);

        // Initialize workflow
        $this->workflowService->initializeDraft($draft);

        $session = $this->requestStack->getSession();
        $session->set('read_wizard', $draft);
        $this->setSelectedListSlug(null); // null = new draft

        return $draft;
    }

    /**
     * Update draft metadata and advance workflow
     */
    public function updateDraftMetadata(CategoryDraft $draft): void
    {
        $this->workflowService->updateMetadata($draft);
        $session = $this->requestStack->getSession();
        $session->set('read_wizard', $draft);
    }

    /**
     * Add articles to draft and advance workflow
     */
    public function addArticlesToDraft(CategoryDraft $draft): void
    {
        $this->workflowService->addArticles($draft);
        $session = $this->requestStack->getSession();
        $session->set('read_wizard', $draft);
    }

    /**
     * Mark draft as ready for review
     */
    public function markReadyForReview(CategoryDraft $draft): bool
    {
        $result = $this->workflowService->markReadyForReview($draft);
        if ($result) {
            $session = $this->requestStack->getSession();
            $session->set('read_wizard', $draft);
        }
        return $result;
    }

    /**
     * Get article coordinates for a specific reading list by slug
     */
    public function getArticleCoordinatesForList(string $slug): array
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user) {
            return [];
        }

        try {
            $key = new Key();
            $pubkeyHex = $key->convertToHex($user->getUserIdentifier());
        } catch (\Throwable $e) {
            return [];
        }

        $repo = $this->em->getRepository(Event::class);
        $events = $repo->findBy(['kind' => 30040, 'pubkey' => $pubkeyHex], ['created_at' => 'DESC']);

        foreach ($events as $ev) {
            if (!$ev instanceof Event) continue;

            $eventSlug = null;
            $isReadingList = false;
            $articles = [];

            foreach ($ev->getTags() as $t) {
                if (!is_array($t)) continue;

                if (($t[0] ?? null) === 'd') {
                    $eventSlug = (string)$t[1];
                }
                if (($t[0] ?? null) === 'type' && in_array($t[1] ?? null, ['reading-list', 'category'])) {
                    $isReadingList = true;
                }
                if (($t[0] ?? null) === 'a') {
                    $articles[] = (string)$t[1];
                }
            }

            if ($isReadingList && $eventSlug === $slug) {
                return $articles;
            }
        }

        return [];
    }
}
