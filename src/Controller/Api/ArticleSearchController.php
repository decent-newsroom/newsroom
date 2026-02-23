<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\Search\ArticleSearchInterface;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/articles')]
class ArticleSearchController extends AbstractController
{
    public function __construct(
        private readonly ArticleSearchInterface $articleSearch,
    ) {
    }

    /**
     * Search articles and return JSON results.
     * GET /api/articles/search?q=query&limit=10
     */
    #[Route('/search', name: 'api_articles_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = trim($request->query->get('q', ''));
        $limit = min((int) $request->query->get('limit', 10), 30);

        if ($query === '') {
            return $this->json(['results' => []]);
        }

        $results = $this->articleSearch->search($query, $limit);

        return $this->json(['results' => $this->mapResults($results)]);
    }

    /**
     * Return the logged-in user's own articles.
     * GET /api/articles/mine?limit=20
     */
    #[Route('/mine', name: 'api_articles_mine', methods: ['GET'])]
    public function mine(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['results' => []], 401);
        }

        try {
            $key = new Key();
            $pubkeyHex = $key->convertToHex($user->getUserIdentifier());
        } catch (\Throwable $e) {
            return $this->json(['results' => []]);
        }

        $limit = min((int) $request->query->get('limit', 20), 50);
        $results = $this->articleSearch->findByPubkey($pubkeyHex, $limit);

        return $this->json(['results' => $this->mapResults($results)]);
    }

    /**
     * @param iterable $articles
     * @return array
     */
    private function mapResults(iterable $articles): array
    {
        $seen = [];
        foreach ($articles as $article) {
            $slug = $article->getSlug();
            $title = $article->getTitle();
            if (empty($slug) || empty($title)) {
                continue;
            }
            $kind = $article->getKind()?->value ?? 30023;
            $coordinate = $kind . ':' . $article->getPubkey() . ':' . $slug;

            // Keep latest by createdAt per coordinate
            if (isset($seen[$coordinate])) {
                $existing = $seen[$coordinate]['_createdAt'];
                $current = $article->getCreatedAt();
                if ($existing && $current && $current <= $existing) {
                    continue;
                }
            }

            $seen[$coordinate] = [
                'title' => $title,
                'summary' => $article->getSummary() ?? '',
                'pubkey' => $article->getPubkey(),
                'slug' => $slug,
                'kind' => $kind,
                'coordinate' => $coordinate,
                'createdAt' => $article->getCreatedAt()?->format('M j, Y') ?? '',
                '_createdAt' => $article->getCreatedAt(),
            ];
        }

        // Strip internal _createdAt key before returning
        return array_values(array_map(function ($item) {
            unset($item['_createdAt']);
            return $item;
        }, $seen));
    }
}

