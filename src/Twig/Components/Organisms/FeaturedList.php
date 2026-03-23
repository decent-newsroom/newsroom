<?php

namespace App\Twig\Components\Organisms;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Service\Search\ArticleSearchInterface;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class FeaturedList
{
    public string $mag;
    public string $category;
    public string $catSlug;
    public string $title;
    public ?string $summary = null;
    public array $list = [];

    /** Whether this category's items are 30041 chapters rather than articles. */
    public bool $isChapterCategory = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ArticleSearchInterface $articleSearch
    ) {
    }

    /**
     * @throws \Exception|Exception
     */
    public function mount($category): void
    {
        $parts = explode(':', $category[1], 3);
        $categorySlug = $parts[2] ?? '';

        // Query the database for the latest category event by slug using native SQL
        $sql = "SELECT e.* FROM event e
                WHERE e.tags @> ?::jsonb
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

        $coordinates = [];
        foreach ($tags as $tag) {
            if ($tag[0] === 'title') {
                $this->title = $tag[1];
            }
            if ($tag[0] === 'summary') {
                $this->summary = $tag[1];
            }
            if ($tag[0] === 'd') {
                $this->catSlug = $tag[1];
            }
            if ($tag[0] === 'a') {
                $coordinates[] = $tag[1];
                if (count($coordinates) >= 5) {
                    break; // Limit to 4 items (+ 1 for safety)
                }
            }
        }

        if (empty($coordinates)) {
            return;
        }

        // Detect whether this category references 30041 chapters or articles
        $firstCoord = $coordinates[0];
        $firstParts = explode(':', $firstCoord, 3);
        $firstKind = (int)($firstParts[0] ?? 0);

        if ($firstKind === KindsEnum::PUBLICATION_CONTENT->value) {
            // This is a chapter-based category (hybrid collection)
            $this->isChapterCategory = true;
            $this->list = $this->fetchChapters($coordinates);
        } else {
            // Standard article-based category
            $slugs = array_map(function ($coordinate) {
                $parts = explode(':', $coordinate, 3);
                return end($parts);
            }, $coordinates);

            $articles = $this->articleSearch->findBySlugs(array_values($slugs), 200);

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

            $orderedList = [];
            foreach ($slugs as $slug) {
                if (isset($slugMap[$slug])) {
                    $orderedList[] = $slugMap[$slug];
                }
            }

            $this->list = array_slice($orderedList, 0, 4);
        }
    }

    /**
     * Fetch 30041 chapter events by their coordinates.
     *
     * @param string[] $coordinates  e.g. ["30041:pubkey:slug", ...]
     * @return array<int, array{slug: string, title: string, summary: ?string, image: ?string, coordinate: string}>
     */
    private function fetchChapters(array $coordinates): array
    {
        $conn = $this->entityManager->getConnection();
        $chapters = [];

        foreach (array_slice($coordinates, 0, 4) as $coordinate) {
            $parts = explode(':', $coordinate, 3);
            if (count($parts) !== 3) {
                continue;
            }
            $slug = $parts[2];

            $sql = "SELECT e.* FROM event e
                    WHERE e.tags @> ?::jsonb
                    AND e.kind = ?
                    ORDER BY e.created_at DESC
                    LIMIT 1";

            $result = $conn->executeQuery($sql, [
                json_encode([['d', $slug]]),
                KindsEnum::PUBLICATION_CONTENT->value,
            ]);

            $eventData = $result->fetchAssociative();
            if ($eventData === false) {
                // Placeholder for unfetched chapter
                $chapters[] = [
                    'slug' => $slug,
                    'title' => $slug,
                    'summary' => null,
                    'image' => null,
                    'coordinate' => $coordinate,
                    'fetched' => false,
                ];
                continue;
            }

            $eventTags = json_decode($eventData['tags'], true) ?? [];
            $title = $slug;
            $summary = null;
            $image = null;
            foreach ($eventTags as $tag) {
                match ($tag[0] ?? '') {
                    'title' => $title = $tag[1] ?? $title,
                    'summary' => $summary ??= $tag[1] ?? null,
                    'image' => $image ??= $tag[1] ?? null,
                    default => null,
                };
            }

            $chapters[] = [
                'slug' => $slug,
                'title' => $title,
                'summary' => $summary,
                'image' => $image,
                'coordinate' => $coordinate,
                'fetched' => true,
            ];
        }

        return $chapters;
    }
}

