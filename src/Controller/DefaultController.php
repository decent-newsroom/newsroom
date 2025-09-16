<?php

declare(strict_types=1);

namespace App\Controller;

use Elastica\Query;
use Elastica\Query\Terms;
use Exception;
use FOS\ElasticaBundle\Finder\FinderInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Psr\Log\LoggerInterface;

class DefaultController extends AbstractController
{
    public function __construct(
        private readonly FinderInterface $finder,
        private readonly CacheInterface $redisCache)
    {
    }

    /**
     * @throws Exception
     * @throws InvalidArgumentException
     */
    #[Route('/', name: 'home')]
    public function index(): Response
    {
        // get latest articles
        $q = new Query();
        $q->setSize(12);
        $q->setSort(['createdAt' => ['order' => 'desc']]);
        $latest = $this->finder->find($q);
        // get newsroom index, loop over categories, pick top three from each and display in sections
        $mag = $this->redisCache->get('magazine-newsroom-magazine-by-newsroom', function (){
            return null;
        });
        $tags = $mag->getTags();

        $cats = array_filter($tags, function($tag) {
            return ($tag[0] === 'a');
        });

        return $this->render('home.html.twig', [
            'indices' => array_values($cats),
            'latest' => $latest
        ]);
    }


    /**
     * @throws InvalidArgumentException
     */
    #[Route('/cat/{slug}', name: 'magazine-category')]
    public function magCategory($slug, CacheInterface $redisCache,
                                FinderInterface $finder,
                                LoggerInterface $logger): Response
    {
        $catIndex = $redisCache->get('magazine-' . $slug, function (){
            throw new Exception('Not found');
        });

        $list = [];
        $coordinates = []; // Store full coordinates (kind:author:slug)
        $category = [];

        // Extract category metadata and article coordinates
        foreach ($catIndex->getTags() as $tag) {
            if ($tag[0] === 'title') {
                $category['title'] = $tag[1];
            }
            if ($tag[0] === 'summary') {
                $category['summary'] = $tag[1];
            }
            if ($tag[0] === 'a') {
                $coordinates[] = $tag[1]; // Store the full coordinate
            }
        }

        if (!empty($coordinates)) {
            // Extract slugs for elasticsearch query
            $slugs = array_map(function($coordinate) {
                $parts = explode(':', $coordinate, 3);
                return end($parts);
            }, $coordinates);
            $slugs = array_filter($slugs); // Remove empty values

            // First filter to only include articles with the slugs we want
            $termsQuery = new Terms('slug', array_values($slugs));

            // Create a Query object to set the size parameter
            $query = new Query($termsQuery);
            $query->setSize(200); // Set size to exceed the number of articles we expect

            $articles = $finder->find($query);

            // Create a map of slug => item to remove duplicates
            $slugMap = [];
            foreach ($articles as $item) {
                $slug = $item->getSlug();
                if ($slug !== '') {
                    // If the slugMap doesn't contain it yet, add it
                    if (!isset($slugMap[$slug])) {
                        $slugMap[$slug] = $item;
                    } else {
                        // If it already exists, compare created_at timestamps and save newest
                        $existingItem = $slugMap[$slug];
                        if ($item->getCreatedAt() > $existingItem->getCreatedAt()) {
                            $slugMap[$slug] = $item;
                        }
                    }
                }
            }

            // Find missing coordinates
            $missingCoordinates = [];
            foreach ($coordinates as $coordinate) {
                $parts = explode(':', $coordinate, 3);
                if (!isset($slugMap[end($parts)])) {
                    $missingCoordinates[] = $coordinate;
                }
            }

            // If we have missing articles, fetch them directly using NostrClient's getArticlesByCoordinates
            if (!empty($missingCoordinates)) {

                $logger->info('There were missing articles', [
                    'missing' => $missingCoordinates
                ]);

//                try {
//                    $nostrArticles = $nostrClient->getArticlesByCoordinates($missingCoordinates);
//
//                    foreach ($nostrArticles as $coordinate => $event) {
//                        $parts = explode(':', $coordinate);
//                        if (count($parts) === 3) {
//                            $article = $articleFactory->createFromLongFormContentEvent($event);
//                            // Save article to database for future queries
//                            $nostrClient->saveEachArticleToTheDatabase($article);
//                            // Add to the slugMap
//                            $slugMap[$article->getSlug()] = $article;
//                        }
//                    }
//                } catch (\Exception $e) {
//                    $logger->error('Error fetching missing articles', [
//                        'error' => $e->getMessage()
//                    ]);
//                }
            }

            // Build ordered list based on original coordinates order
            foreach ($coordinates as $coordinate) {
                $parts = explode(':', $coordinate,3);
                if (isset($slugMap[end($parts)])) {
                    $list[] = $slugMap[end($parts)];
                }
            }
        }

        return $this->render('pages/category.html.twig', [
            'list' => $list,
            'category' => $category,
            'index' => $catIndex
        ]);
    }

    /**
     * OG Preview endpoint for URLs
     */
    #[Route('/og-preview/', name: 'og_preview', methods: ['POST'])]
    public function ogPreview(RequestStack $requestStack): Response
    {
        $request = $requestStack->getCurrentRequest();
        $data = json_decode($request->getContent(), true);
        $url = $data['url'] ?? null;
        if (!$url) {
            return new Response('<div class="alert alert-warning">No URL provided.</div>', 400);
        }
        try {
            $embed = new \Embed\Embed();
            $info = $embed->get($url);
            if (!$info) {
                throw new \Exception('No OG data found');
            }
            return $this->render('components/Molecules/OgPreview.html.twig', [
                'og' => [
                    'title' => $info->title,
                    'description' => $info->description,
                    'image' => $info->image,
                    'url' => $url
                ]
            ]);
        } catch (\Exception $e) {
            return new Response('<div class="alert alert-warning">Unable to load OG preview for ' . htmlspecialchars($url) . '</div>', 200);
        }
    }
}
