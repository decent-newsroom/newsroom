<?php

namespace App\Twig\Components\Organisms;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class FeaturedList
{
    private const MAX_NESTING_DEPTH = 3;

    public string $mag;
    public string $category;
    public string $catSlug;
    public string $title;
    public ?string $summary = null;
    public array $list = [];
    public int $depth = 0;

    /** Whether this category's items are 30041 chapters rather than articles. */
    public bool $isChapterCategory = false;

    /** Whether this category's items are 30040 subcategories rather than articles. */
    public bool $isSubcategoryCategory = false;

    /** Prevent unbounded recursion on cyclic nested categories. */
    public bool $canRenderChildren = true;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws \Exception|Exception
     */
    public function mount($category): void
    {
        if (!is_array($category) || !isset($category[1]) || !is_string($category[1])) {
            return;
        }

        $parts = explode(':', $category[1], 3);
        if (count($parts) !== 3) {
            return;
        }
        $categoryKind = (int) ($parts[0] ?? 0);
        $categoryPubkey = (string) ($parts[1] ?? '');
        $categorySlug = (string) ($parts[2] ?? '');
        if ($categoryKind <= 0 || $categoryPubkey === '' || $categorySlug === '') {
            return;
        }

        $currentCategoryCoordinate = $categoryKind . ':' . $categoryPubkey . ':' . $categorySlug;
        $this->canRenderChildren = $this->depth < self::MAX_NESTING_DEPTH;

        $conn = $this->entityManager->getConnection();
        $eventData = $conn->executeQuery(
            'SELECT e.* FROM event e
             WHERE e.kind = :kind
               AND e.pubkey = :pubkey
               AND e.d_tag = :slug
             ORDER BY e.created_at DESC
             LIMIT 1',
            [
                'kind' => $categoryKind,
                'pubkey' => $categoryPubkey,
                'slug' => $categorySlug,
            ]
        )->fetchAssociative();

        if ($eventData === false) {
            // Backward compatibility for older rows without d_tag backfill.
            $eventData = $conn->executeQuery(
                "SELECT e.* FROM event e
                 WHERE e.kind = :kind
                   AND e.pubkey = :pubkey
                   AND EXISTS (
                     SELECT 1 FROM jsonb_array_elements(e.tags) AS tag
                     WHERE tag->>0 = 'd' AND tag->>1 = :slug
                   )
                 ORDER BY e.created_at DESC
                 LIMIT 1",
                [
                    'kind' => $categoryKind,
                    'pubkey' => $categoryPubkey,
                    'slug' => $categorySlug,
                ]
            )->fetchAssociative();
        }

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
                if (($tag[1] ?? null) === $currentCategoryCoordinate) {
                    continue;
                }
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
        } elseif ($firstKind === KindsEnum::PUBLICATION_INDEX->value) {
            // This is a subcategory container — children are other 30040 index events
            $this->isSubcategoryCategory = true;
            // Pass raw coordinate tags (as they appear in the parent event) so the template
            // can render a nested FeaturedList for each subcategory.
            $this->list = array_map(fn(string $c) => ['a', $c], $coordinates);
        } else {
            // Standard article-based category: resolve by full coordinates to avoid slug collisions.
            /** @var ArticleRepository $articleRepo */
            $articleRepo = $this->entityManager->getRepository(\App\Entity\Article::class);

            $parsed = [];
            foreach ($coordinates as $coordinateValue) {
                $coordParts = explode(':', $coordinateValue, 3);
                if (count($coordParts) !== 3) {
                    continue;
                }
                $parsed[] = [
                    'kind' => (int) $coordParts[0],
                    'pubkey' => $coordParts[1],
                    'slug' => $coordParts[2],
                ];
            }

            $coordMap = $articleRepo->findByCoordinates($parsed);
            $orderedList = [];
            foreach ($coordinates as $coordinateValue) {
                $coordParts = explode(':', $coordinateValue, 3);
                if (count($coordParts) !== 3) {
                    continue;
                }
                $key = ((int) $coordParts[0]) . ':' . $coordParts[1] . ':' . $coordParts[2];
                if (isset($coordMap[$key])) {
                    $orderedList[] = $coordMap[$key];
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
        /** @var EventRepository $eventRepository */
        $eventRepository = $this->entityManager->getRepository(Event::class);
        $limitedCoordinates = array_slice($coordinates, 0, 4);
        $chapterMap = $eventRepository->findByCoordinates($limitedCoordinates);

        $chapters = [];

        foreach ($limitedCoordinates as $coordinate) {
            $parts = explode(':', $coordinate, 3);
            if (count($parts) !== 3) {
                continue;
            }
            $slug = $parts[2];

            $chapter = $chapterMap[$coordinate] ?? null;
            if (!$chapter instanceof Event) {
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

            $chapters[] = [
                'slug' => $slug,
                'title' => $chapter->getTitle() ?? $slug,
                'summary' => $chapter->getSummary(),
                'image' => $chapter->getImage(),
                'coordinate' => $coordinate,
                'fetched' => true,
            ];
        }

        return $chapters;
    }
}

