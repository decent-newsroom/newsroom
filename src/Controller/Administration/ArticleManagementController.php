<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Service\RedisCacheService;
use Elastica\Query;
use FOS\ElasticaBundle\Finder\PaginatedFinderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class ArticleManagementController extends AbstractController
{
    // This controller will handle article management functionalities.
    #[Route('/admin/articles', name: 'admin_articles')]

    public function listArticles(
        #[Autowire(service: 'fos_elastica.finder.articles')] PaginatedFinderInterface $finder,
        #[Autowire(service: 'fos_elastica.index.articles')] \Elastica\Index $index,
        RedisCacheService $redisCacheService
    ): Response
    {
        // Query: latest 50, deduplicated by slug, sorted by createdAt desc
        $query = [
            'size' => 100, // fetch more to allow deduplication
            'sort' => [
                ['createdAt' => ['order' => 'desc']]
            ]
        ];
        $results = $finder->find($query);
        $unique = [];
        $articles = [];
        foreach ($results as $article) {
            $slug = $article->getSlug();
            if (!isset($unique[$slug])) {
                $unique[$slug] = true;
                $articles[] = $article;
                if (count($articles) >= 50) break;
            }
        }
        // Separate query for aggregations
        $aggQuery = new Query([
            'size' => 0,
            'aggs' => [
                'tags' => [
                    'terms' => [
                        'field' => 'topics',
                        'size' => 50
                    ]
                ]
            ]
        ]);
        $aggResults = $index->search($aggQuery);
        $tagCounts = $aggResults->getAggregations()['tags']['buckets'] ?? [];
        return $this->render('admin/articles.html.twig', [
            'articles' => $articles,
            'indexes' => [],
            'tagCounts' => $tagCounts,
        ]);
    }

    #[Route('/admin/articles/add-to-index', name: 'admin_article_add_to_index', methods: ['POST'])]
    public function addToIndex(
        Request $request,
        RedisCacheService $redisCacheService
    ): RedirectResponse {
        $slug = $request->request->get('slug');
        $indexKey = $request->request->get('index_key');
        if (!$slug || !$indexKey) {
            $this->addFlash('danger', 'Missing article or index selection.');
            return $this->redirectToRoute('admin_articles');
        }
        // Build the tag: ['a', 'article:'.$slug]
        $articleTag = ['a', 'article:' . $slug];
        $success = $redisCacheService->addArticleToIndex($indexKey, $articleTag);
        if ($success) {
            $this->addFlash('success', 'Article added to index.');
        } else {
            $this->addFlash('danger', 'Failed to add article to index.');
        }
        return $this->redirectToRoute('admin_articles');
    }
}
