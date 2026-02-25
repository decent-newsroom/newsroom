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

        return $this->render('my_content/index.html.twig', [
            'articles' => $articles,
            'drafts' => $drafts,
            'readingLists' => $readingLists,
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

