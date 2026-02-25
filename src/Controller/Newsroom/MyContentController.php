<?php

declare(strict_types=1);

namespace App\Controller\Newsroom;

use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use Doctrine\ORM\EntityManagerInterface;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * My Content Controller
 *
 * Provides a unified "file manager" view of a user's articles, drafts,
 * and reading lists / curation sets.
 */
class MyContentController extends AbstractController
{
    #[Route('/my-content', name: 'my_content')]
    #[IsGranted('ROLE_USER')]
    public function index(EntityManagerInterface $em): Response
    {
        $key = new Key();
        $pubkeyHex = $key->convertToHex($this->getUser()->getUserIdentifier());

        // ── Published articles ────────────────────────────────────────────
        $allArticles = $em->getRepository(Article::class)
            ->findBy(
                ['pubkey' => $pubkeyHex, 'kind' => KindsEnum::LONGFORM],
                ['createdAt' => 'DESC']
            );

        // Collapse by slug – keep only the latest revision
        $articles = array_values(array_reduce($allArticles, function ($carry, $item) {
            if (!isset($carry[$item->getSlug()])) {
                $carry[$item->getSlug()] = $item;
            }
            return $carry;
        }, []) ?? []);

        // ── Drafts ────────────────────────────────────────────────────────
        $allDrafts = $em->getRepository(Article::class)
            ->findBy(
                ['pubkey' => $pubkeyHex, 'kind' => KindsEnum::LONGFORM_DRAFT],
                ['createdAt' => 'DESC']
            );

        $drafts = array_values(array_reduce($allDrafts, function ($carry, $item) {
            if (!isset($carry[$item->getSlug()])) {
                $carry[$item->getSlug()] = $item;
            }
            return $carry;
        }, []) ?? []);

        // ── Reading lists & curation sets ─────────────────────────────────
        $readingLists = $this->fetchReadingLists($em, $pubkeyHex);

        // ── Bookmarks ─────────────────────────────────────────────────────
        $bookmarks = $this->fetchBookmarks($em, $pubkeyHex);

        return $this->render('my_content/index.html.twig', [
            'articles' => $articles,
            'drafts' => $drafts,
            'readingLists' => $readingLists,
            'bookmarks' => $bookmarks,
        ]);
    }

    /**
     * Fetch reading lists and curation sets for the given pubkey.
     * Reuses the same logic that was in ReadingListController::index.
     */
    private function fetchReadingLists(EntityManagerInterface $em, string $pubkeyHex): array
    {
        $repo = $em->getRepository(Event::class);

        $events = $repo->createQueryBuilder('e')
            ->where('e.kind IN (:kinds)')
            ->andWhere('e.pubkey = :pubkey')
            ->setParameter('kinds', [
                KindsEnum::PUBLICATION_INDEX->value,  // 30040
                KindsEnum::CURATION_SET->value,       // 30004
                KindsEnum::CURATION_VIDEOS->value,    // 30005
                KindsEnum::CURATION_PICTURES->value,  // 30006
            ])
            ->setParameter('pubkey', $pubkeyHex)
            ->orderBy('e.created_at', 'DESC')
            ->getQuery()
            ->getResult();

        $lists = [];
        $seenSlugs = [];

        foreach ($events as $ev) {
            if (!$ev instanceof Event) {
                continue;
            }

            $tags = $ev->getTags();
            $kind = $ev->getKind();
            $hasMagazineReferences = false;
            $hasAnyATags = false;
            $hasAnyETags = false;
            $title = null;
            $slug = null;
            $summary = null;
            $itemCount = 0;

            $typeLabel = match ($kind) {
                30040 => 'Reading List',
                30004 => 'Articles/Notes',
                30005 => 'Videos',
                30006 => 'Pictures',
                default => null,
            };

            $coordinates = []; // Full coordinate strings (e.g. "30023:pubkey:slug")

            foreach ($tags as $t) {
                if (!is_array($t)) {
                    continue;
                }
                if (($t[0] ?? null) === 'title') { $title = (string)($t[1] ?? ''); }
                if (($t[0] ?? null) === 'summary') { $summary = (string)($t[1] ?? ''); }
                if (($t[0] ?? null) === 'd') { $slug = (string)($t[1] ?? ''); }

                if (($t[0] ?? null) === 'a' && isset($t[1])) {
                    $hasAnyATags = true;
                    $itemCount++;
                    $coordinates[] = (string)$t[1];
                    if (str_starts_with((string)$t[1], '30040:')) {
                        $hasMagazineReferences = true;
                    }
                }
                if (($t[0] ?? null) === 'e' && isset($t[1])) {
                    $hasAnyETags = true;
                    $itemCount++;
                }
            }

            // Skip magazine indexes (events that reference other 30040 events)
            if ($kind === 30040 && $hasMagazineReferences) {
                continue;
            }

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
                'kind' => $kind,
                'type' => $typeLabel,
                'itemCount' => $itemCount,
                'isEmpty' => !$hasAnyATags && !$hasAnyETags,
                'articles' => $this->resolveCoordinates($em, $coordinates),
            ];
        }

        return $lists;
    }

    /**
     * Fetch bookmarks and bookmark sets for the given pubkey.
     * Kinds: 10003 (Bookmarks), 30003 (Bookmark Sets).
     *
     * @return array<array{title: string, kind: int, type: string, createdAt: mixed, itemCount: int, items: array}>
     */
    private function fetchBookmarks(EntityManagerInterface $em, string $pubkeyHex): array
    {
        $repo = $em->getRepository(Event::class);

        $events = $repo->createQueryBuilder('e')
            ->where('e.pubkey = :pubkey')
            ->andWhere('e.kind IN (:kinds)')
            ->setParameter('pubkey', $pubkeyHex)
            ->setParameter('kinds', [
                KindsEnum::BOOKMARKS->value,      // 10003
                KindsEnum::BOOKMARK_SETS->value,   // 30003
            ])
            ->orderBy('e.created_at', 'DESC')
            ->setMaxResults(50)
            ->getQuery()
            ->getResult();

        $bookmarks = [];
        $seenIdentifiers = [];

        foreach ($events as $ev) {
            if (!$ev instanceof Event) {
                continue;
            }

            $tags = $ev->getTags();
            $kind = $ev->getKind();
            $title = null;
            $identifier = null;
            $summary = null;
            $items = [];

            $typeLabel = match ($kind) {
                KindsEnum::BOOKMARKS->value => 'Bookmarks',
                KindsEnum::BOOKMARK_SETS->value => 'Bookmark Set',
                default => 'Bookmarks',
            };

            foreach ($tags as $t) {
                if (!is_array($t) || count($t) < 2) {
                    continue;
                }
                switch ($t[0]) {
                    case 'd':
                        $identifier = (string)$t[1];
                        break;
                    case 'title':
                        $title = (string)$t[1];
                        break;
                    case 'summary':
                        $summary = (string)$t[1];
                        break;
                    case 'e':
                        $items[] = ['type' => 'e', 'value' => $t[1], 'label' => substr($t[1], 0, 12) . '…', 'relay' => $t[2] ?? null];
                        break;
                    case 'a':
                        $items[] = ['type' => 'a', 'value' => $t[1], 'label' => $t[1], 'relay' => $t[2] ?? null];
                        break;
                    case 'p':
                        $items[] = ['type' => 'p', 'value' => $t[1], 'label' => substr($t[1], 0, 12) . '…'];
                        break;
                    case 't':
                        $items[] = ['type' => 't', 'value' => $t[1], 'label' => '#' . $t[1]];
                        break;
                }
            }

            // Collapse 30003 by identifier (d-tag); 10003 is a singleton
            $dedupeKey = $kind === KindsEnum::BOOKMARK_SETS->value
                ? ($identifier ?: '__no_id__:' . $ev->getId())
                : '__bookmarks_' . $kind;

            if (isset($seenIdentifiers[$dedupeKey])) {
                continue;
            }
            $seenIdentifiers[$dedupeKey] = true;

            // Resolve 'a' tag coordinates to article titles
            $aCoordinates = array_filter($items, fn($i) => $i['type'] === 'a');
            $resolvedArticles = $this->resolveCoordinates(
                $em,
                array_map(fn($i) => $i['value'], $aCoordinates)
            );
            $resolvedByCoord = [];
            foreach ($resolvedArticles as $r) {
                $resolvedByCoord[$r['coordinate']] = $r;
            }

            // Enrich items with resolved titles
            foreach ($items as &$item) {
                if ($item['type'] === 'a' && isset($resolvedByCoord[$item['value']])) {
                    $resolved = $resolvedByCoord[$item['value']];
                    $item['label'] = $resolved['title'];
                    $item['found'] = $resolved['found'];
                    $item['slug'] = $resolved['slug'];
                    $item['pubkey'] = $resolved['pubkey'];
                } else {
                    $item['found'] = $item['type'] !== 'a'; // non-'a' items are always "found"
                }
            }
            unset($item);

            $bookmarks[] = [
                'id' => $ev->getId(),
                'title' => $title ?: ($identifier ?: ($kind === KindsEnum::BOOKMARKS->value ? 'Bookmarks' : '(untitled)')),
                'summary' => $summary,
                'identifier' => $identifier,
                'kind' => $kind,
                'type' => $typeLabel,
                'createdAt' => $ev->getCreatedAt(),
                'itemCount' => count($items),
                'items' => $items,
            ];
        }

        return $bookmarks;
    }

    /**
     * Resolve an array of Nostr coordinate strings (kind:pubkey:slug) to Article entities.
     *
     * @param  string[] $coordinates  e.g. ["30023:abc123:my-article", …]
     * @return array<array{title: string, slug: string, pubkey: string, kind: int, createdAt: \DateTimeInterface|null, coordinate: string}>
     */
    private function resolveCoordinates(EntityManagerInterface $em, array $coordinates): array
    {
        if (empty($coordinates)) {
            return [];
        }

        // Parse coordinates into lookup-friendly parts
        $parsed = [];
        foreach ($coordinates as $coord) {
            $parts = explode(':', $coord, 3);
            if (count($parts) === 3) {
                $parsed[] = [
                    'kind' => (int) $parts[0],
                    'pubkey' => $parts[1],
                    'slug' => $parts[2],
                    'coordinate' => $coord,
                ];
            }
        }

        if (empty($parsed)) {
            return [];
        }

        // Batch-query all slugs at once, then match in PHP
        $slugs = array_unique(array_column($parsed, 'slug'));
        $dbArticles = $em->getRepository(Article::class)
            ->createQueryBuilder('a')
            ->where('a.slug IN (:slugs)')
            ->setParameter('slugs', $slugs)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        // Index by pubkey:slug (keep latest only)
        $indexed = [];
        foreach ($dbArticles as $a) {
            $key = $a->getPubkey() . ':' . $a->getSlug();
            if (!isset($indexed[$key])) {
                $indexed[$key] = $a;
            }
        }

        // Build result in original coordinate order
        $result = [];
        foreach ($parsed as $p) {
            $key = $p['pubkey'] . ':' . $p['slug'];
            $article = $indexed[$key] ?? null;
            $result[] = [
                'title' => $article ? ($article->getTitle() ?: $p['slug']) : $p['slug'],
                'slug' => $p['slug'],
                'pubkey' => $p['pubkey'],
                'kind' => $article ? $article->getKind() : $p['kind'],
                'createdAt' => $article?->getCreatedAt(),
                'coordinate' => $p['coordinate'],
                'found' => $article !== null,
            ];
        }

        return $result;
    }
}

