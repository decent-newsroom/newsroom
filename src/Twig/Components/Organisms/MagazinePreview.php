<?php

declare(strict_types=1);

namespace App\Twig\Components\Organisms;

use App\Entity\Article;
use App\Entity\Event;
use App\Entity\UnfoldSite;
use App\Enum\KindsEnum;
use App\Repository\UnfoldSiteRepository;
use App\Service\Search\ArticleSearchInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Renders a magazine preview with a horizontal category slider.
 * Each category shows its title and the latest article preview card.
 *
 * Resolves the magazine via an UnfoldSite subdomain mapping, so the
 * external URL and magazine coordinate are derived from the subdomain config.
 *
 * Usage: <twig:Organisms:MagazinePreview subdomain="newsroommag" />
 */
#[AsTwigComponent]
final class MagazinePreview
{
    /** Subdomain of the UnfoldSite to preview */
    public string $subdomain;

    /** Resolved external URL (https://{subdomain}.{baseDomain}) */
    public ?string $externalUrl = null;

    /** Resolved magazine title */
    public ?string $title = null;

    /** Resolved magazine summary */
    public ?string $summary = null;

    /** Resolved magazine image */
    public ?string $image = null;

    /** Internal magazine slug for linking */
    public ?string $mag = null;

    /**
     * Categories with their latest article.
     * @var array<int, array{title: string, slug: string, summary: ?string, article: ?Article}>
     */
    public array $categories = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UnfoldSiteRepository $unfoldSiteRepository,
        private readonly ArticleSearchInterface $articleSearch,
        private readonly LoggerInterface $logger,
        private readonly string $baseDomain,
    ) {
    }

    public function mount($subdomain): void
    {
        $this->subdomain = $subdomain;
        // 1. Look up the UnfoldSite by subdomain
        $site = $this->unfoldSiteRepository->findBySubdomain($this->subdomain);
        if ($site === null) {
            return;
        }

        // 2. Parse the coordinate (format: 30040:pubkey:slug)
        $coordinate = $site->getCoordinate();
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3) {
            $this->logger->warning('Invalid magazine coordinate on UnfoldSite', [
                'subdomain' => $this->subdomain,
                'coordinate' => $coordinate,
            ]);
            return;
        }

        $magazineSlug = $parts[2];
        $this->mag = $magazineSlug;

        // 3. Construct external URL
        $host = rtrim($this->baseDomain, '/');
        $this->externalUrl = sprintf('https://%s.%s', $this->subdomain, $host);

        // 4. Find the magazine event by coordinate parts (pubkey + slug)
        $magazine = $this->findMagazine($parts[1], $magazineSlug);
        if ($magazine === null) {
            return;
        }

        $this->title = $magazine->getTitle();
        $this->summary = $magazine->getSummary();
        $this->image = $magazine->getImage();

        // 5. Extract category coordinates (sub-30040 references)
        $categoryCoordinates = [];
        foreach ($magazine->getTags() as $tag) {
            if (($tag[0] ?? '') === 'a' && isset($tag[1])) {
                $tagParts = explode(':', $tag[1], 3);
                if (count($tagParts) === 3 && (int) $tagParts[0] === KindsEnum::PUBLICATION_INDEX->value) {
                    $categoryCoordinates[] = $tag[1];
                }
            }
        }

        // 6. For each category, find its title and latest article
        foreach ($categoryCoordinates as $catCoordinate) {
            $category = $this->processCategory($catCoordinate);
            if ($category !== null) {
                $this->categories[] = $category;
            }
        }
    }

    private function findMagazine(string $pubkey, string $slug): ?Event
    {
        $events = $this->entityManager->getRepository(Event::class)->findBy([
            'kind' => KindsEnum::PUBLICATION_INDEX,
            'pubkey' => $pubkey,
        ]);

        $matching = array_filter($events, fn(Event $e) => $e->getSlug() === $slug);

        if (empty($matching)) {
            return null;
        }

        usort($matching, fn(Event $a, Event $b) => $b->getCreatedAt() <=> $a->getCreatedAt());

        return array_shift($matching);
    }

    /**
     * @return array{title: string, slug: string, summary: ?string, article: ?Article}|null
     */
    private function processCategory(string $coordinate): ?array
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3) {
            return null;
        }

        $categorySlug = $parts[2];

        // Query the category event from DB
        $conn = $this->entityManager->getConnection();
        $sql = "SELECT e.* FROM event e
                WHERE e.tags @> ?::jsonb
                ORDER BY e.created_at DESC
                LIMIT 1";

        try {
            $result = $conn->executeQuery($sql, [
                json_encode([['d', $categorySlug]]),
            ]);
            $eventData = $result->fetchAssociative();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to query category event', [
                'slug' => $categorySlug,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if ($eventData === false) {
            return null;
        }

        $tags = json_decode($eventData['tags'], true) ?? [];

        $title = $categorySlug;
        $summary = null;
        $articleCoordinates = [];

        foreach ($tags as $tag) {
            match ($tag[0] ?? '') {
                'title' => $title = $tag[1] ?? $title,
                'summary' => $summary ??= $tag[1] ?? null,
                'a' => $articleCoordinates[] = $tag[1] ?? '',
                default => null,
            };
        }

        // Get the first (latest) article from the category
        $article = null;
        foreach ($articleCoordinates as $aCoord) {
            $aParts = explode(':', $aCoord, 3);
            if (count($aParts) !== 3) {
                continue;
            }

            $artSlug = $aParts[2];
            $articles = $this->articleSearch->findBySlugs([$artSlug], 1);
            if (!empty($articles)) {
                $article = $articles[0];
                break;
            }
        }

        return [
            'title' => $title,
            'slug' => $categorySlug,
            'summary' => $summary,
            'article' => $article,
        ];
    }
}

