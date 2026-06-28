<?php

declare(strict_types=1);

namespace App\Controller\Newsroom;

use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Helper\NavigationBuilderTrait;
use App\Repository\ArticleRepository;
use Doctrine\ORM\EntityManagerInterface;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Provides a searchable publishing inventory for a user's articles, drafts,
 * and reading lists / curation sets.
 */
class MyContentController extends AbstractController
{
    use NavigationBuilderTrait;

    #[Route('/my-content', name: 'my_content')]
    #[IsGranted('ROLE_USER')]
    public function index(EntityManagerInterface $em, Request $request): Response
    {
        $key = new Key();
        $pubkeyHex = $key->convertToHex($this->getUser()->getUserIdentifier());

        /** @var ArticleRepository $articleRepository */
        $articleRepository = $em->getRepository(Article::class);
        $articles = $articleRepository->findLatestRevisionsByAuthorAndKind($pubkeyHex, KindsEnum::LONGFORM);
        $drafts = $articleRepository->findLatestRevisionsByAuthorAndKind($pubkeyHex, KindsEnum::LONGFORM_DRAFT);
        $readingLists = $this->fetchReadingLists($em, $pubkeyHex);

        $contentItems = [];
        foreach ($articles as $article) {
            $contentItems[] = [
                'category' => 'published',
                'title' => $article->getTitle() ?: $article->getSlug(),
                'summary' => $article->getSummary(),
                'updatedAt' => $article->getPublishedAt() ?? $article->getCreatedAt(),
                'searchText' => implode(' ', array_filter([
                    $article->getTitle(),
                    $article->getSummary(),
                    $article->getSlug(),
                ])),
                'article' => $article,
            ];
        }

        foreach ($drafts as $draft) {
            $contentItems[] = [
                'category' => 'drafts',
                'title' => $draft->getTitle() ?: $draft->getSlug(),
                'summary' => $draft->getSummary(),
                'updatedAt' => $draft->getCreatedAt(),
                'searchText' => implode(' ', array_filter([
                    $draft->getTitle(),
                    $draft->getSummary(),
                    $draft->getSlug(),
                ])),
                'article' => $draft,
            ];
        }

        foreach ($readingLists as $readingList) {
            $contentItems[] = [
                'category' => 'lists',
                'title' => $readingList['title'],
                'summary' => $readingList['summary'],
                'updatedAt' => $readingList['createdAt'],
                'searchText' => implode(' ', array_filter([
                    $readingList['title'],
                    $readingList['summary'],
                    $readingList['slug'],
                ])),
                'list' => $readingList,
            ];
        }

        $counts = [
            'all' => count($contentItems),
            'published' => count($articles),
            'drafts' => count($drafts),
            'lists' => count($readingLists),
        ];

        $activeType = $request->query->getString('type', 'all');
        if (!in_array($activeType, ['all', 'drafts', 'published', 'lists'], true)) {
            $activeType = 'all';
        }

        $searchQuery = trim($request->query->getString('q'));
        $normalizedQuery = mb_strtolower($searchQuery);
        $sort = $request->query->getString('sort', 'recent');
        if (!in_array($sort, ['recent', 'title'], true)) {
            $sort = 'recent';
        }

        $filteredItems = array_values(array_filter(
            $contentItems,
            static function (array $item) use ($activeType, $normalizedQuery): bool {
                if ($activeType !== 'all' && $item['category'] !== $activeType) {
                    return false;
                }

                return $normalizedQuery === ''
                    || str_contains(mb_strtolower($item['searchText']), $normalizedQuery);
            },
        ));

        usort(
            $filteredItems,
            static function (array $left, array $right) use ($sort): int {
                if ($sort === 'title') {
                    return strnatcasecmp((string) $left['title'], (string) $right['title']);
                }

                return self::toTimestamp($right['updatedAt'])
                    <=> self::toTimestamp($left['updatedAt']);
            },
        );

        $pageSize = 25;
        $filteredCount = count($filteredItems);
        $totalPages = max(1, (int) ceil($filteredCount / $pageSize));
        $page = min(max(1, $request->query->getInt('page', 1)), $totalPages);
        $visibleItems = array_slice($filteredItems, ($page - 1) * $pageSize, $pageSize);

        return $this->render('my_content/index.html.twig', [
            'newsroomNav' => $this->buildNewsroomNav(),
            'contentItems' => $visibleItems,
            'counts' => $counts,
            'activeType' => $activeType,
            'searchQuery' => $searchQuery,
            'sort' => $sort,
            'filteredCount' => $filteredCount,
            'page' => $page,
            'totalPages' => $totalPages,
        ]);
    }

    private static function toTimestamp(mixed $value): int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Fetch the latest reading-list and curation-set revision for each slug.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchReadingLists(EntityManagerInterface $em, string $pubkeyHex): array
    {
        $events = $em->getRepository(Event::class)
            ->createQueryBuilder('e')
            ->where('e.kind IN (:kinds)')
            ->andWhere('e.pubkey = :pubkey')
            ->setParameter('kinds', [
                KindsEnum::PUBLICATION_INDEX->value,
                KindsEnum::CURATION_SET->value,
                KindsEnum::CURATION_VIDEOS->value,
                KindsEnum::CURATION_PICTURES->value,
            ])
            ->setParameter('pubkey', $pubkeyHex)
            ->orderBy('e.created_at', 'DESC')
            ->getQuery()
            ->getResult();

        $lists = [];
        $seenSlugs = [];

        foreach ($events as $event) {
            if (!$event instanceof Event) {
                continue;
            }

            $kind = $event->getKind();
            $hasMagazineReferences = false;
            $hasAnyATags = false;
            $hasAnyETags = false;
            $title = null;
            $slug = null;
            $summary = null;
            $itemCount = 0;

            $typeKey = match ($kind) {
                KindsEnum::PUBLICATION_INDEX->value => 'my_content.type.reading_list',
                KindsEnum::CURATION_SET->value => 'my_content.type.articles_notes',
                KindsEnum::CURATION_VIDEOS->value => 'my_content.type.videos',
                KindsEnum::CURATION_PICTURES->value => 'my_content.type.pictures',
                default => 'my_content.type.reading_list',
            };

            foreach ($event->getTags() as $tag) {
                if (!is_array($tag)) {
                    continue;
                }

                if (($tag[0] ?? null) === 'title') {
                    $title = (string) ($tag[1] ?? '');
                }
                if (($tag[0] ?? null) === 'summary') {
                    $summary = (string) ($tag[1] ?? '');
                }
                if (($tag[0] ?? null) === 'd') {
                    $slug = (string) ($tag[1] ?? '');
                }
                if (($tag[0] ?? null) === 'a' && isset($tag[1])) {
                    $hasAnyATags = true;
                    ++$itemCount;
                    if (str_starts_with((string) $tag[1], '30040:')) {
                        $hasMagazineReferences = true;
                    }
                }
                if (($tag[0] ?? null) === 'e' && isset($tag[1])) {
                    $hasAnyETags = true;
                    ++$itemCount;
                }
            }

            // Magazine indexes reference other 30040 events; they are managed
            // from the dedicated magazine workspace.
            if ($kind === KindsEnum::PUBLICATION_INDEX->value && $hasMagazineReferences) {
                continue;
            }

            $dedupeKey = $kind . ':' . ($slug ?: '__no_slug__:' . $event->getId());
            if (isset($seenSlugs[$dedupeKey])) {
                continue;
            }
            $seenSlugs[$dedupeKey] = true;

            $lists[] = [
                'id' => $event->getId(),
                'title' => $title ?: $slug,
                'summary' => $summary,
                'slug' => $slug,
                'createdAt' => $event->getCreatedAt(),
                'pubkey' => $event->getPubkey(),
                'kind' => $kind,
                'typeKey' => $typeKey,
                'itemCount' => $itemCount,
                'isEmpty' => !$hasAnyATags && !$hasAnyETags,
            ];
        }

        return $lists;
    }
}
