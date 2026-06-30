<?php

declare(strict_types=1);

namespace App\Controller\Newsroom;

use App\Entity\Article;
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
 * and article publishing work.
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

        $counts = [
            'all' => count($contentItems),
            'published' => count($articles),
            'drafts' => count($drafts),
        ];

        $activeType = $request->query->getString('type', 'all');
        if (!in_array($activeType, ['all', 'drafts', 'published'], true)) {
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
}
