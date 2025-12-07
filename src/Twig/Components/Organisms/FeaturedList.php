<?php

namespace App\Twig\Components\Organisms;

use Doctrine\ORM\EntityManagerInterface;
use Elastica\Query;
use Elastica\Query\Terms;
use FOS\ElasticaBundle\Finder\FinderInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class FeaturedList
{
    public string $mag;
    public string $category;
    public string $catSlug;
    public string $title;
    public array $list = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FinderInterface $finder)
    {
    }

    /**
     * @throws \Exception
     */
    public function mount($category): void
    {
        $parts = explode(':', $category[1], 3);
        $categorySlug = $parts[2] ?? '';

        // Query the database for the latest category event by slug using native SQL
        $sql = "SELECT e.* FROM event e
                WHERE e.tags::jsonb @> ?::jsonb
                ORDER BY e.created_at DESC
                LIMIT 1";

        $conn = $this->entityManager->getConnection();
        $result = $conn->executeQuery($sql, [
            json_encode([['d', $categorySlug]])
        ]);

        $eventData = $result->fetchAssociative();

        if ($eventData === false) {
            return;
        }

        $tags = json_decode($eventData['tags'], true);

        $slugs = [];
        foreach ($tags as $tag) {
            if ($tag[0] === 'title') {
                $this->title = $tag[1];
            }
            if ($tag[0] === 'd') {
                $this->catSlug = $tag[1];
            }
            if ($tag[0] === 'a') {
                $parts = explode(':', $tag[1], 3);
                $slugs[] = end($parts);
                if (count($slugs) >= 5) {
                    break; // Limit to 4 items
                }
            }
        }

        $articles = $this->articleSearch->findBySlugs(array_values($slugs), 200);

        // Create a map of slug => item
        $slugMap = [];
        foreach ($articles as $article) {
            $slug = $article->getSlug();
            if ($slug !== '') {
                if (!isset($slugMap[$slug])) {
                    $slugMap[$slug] = $article;
                } elseif ($article->getCreatedAt() > $slugMap[$slug]->getCreatedAt()) {
                    $slugMap[$slug] = $article;
                }
            }
        }

        // Build ordered list based on original slugs order
        $orderedList = [];
        foreach ($slugs as $slug) {
            if (isset($slugMap[$slug])) {
                $orderedList[] = $slugMap[$slug];
            }
        }

        $this->list = array_slice($orderedList, 0, 4);
    }
}
