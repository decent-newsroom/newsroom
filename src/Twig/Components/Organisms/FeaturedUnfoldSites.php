<?php

declare(strict_types=1);

namespace App\Twig\Components\Organisms;

use App\Entity\Event;
use App\Entity\UnfoldSite;
use App\Enum\KindsEnum;
use App\Repository\UnfoldSiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Renders a featured strip of Unfold-hosted magazine sites at the top
 * of the articles page, giving them premium visibility.
 *
 * Resolves each UnfoldSite's magazine event to extract title, summary,
 * and cover image. Results are cached for 15 minutes.
 *
 * Usage: <twig:Organisms:FeaturedUnfoldSites />
 */
#[AsTwigComponent]
final class FeaturedUnfoldSites
{
    /**
     * Resolved sites with metadata.
     * @var array<int, array{subdomain: string, url: string, title: string, summary: ?string, image: ?string, coordinate: string}>
     */
    public array $sites = [];

    public function __construct(
        private readonly UnfoldSiteRepository $unfoldSiteRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $baseDomain,
    ) {
    }

    public function mount(): void
    {
        $this->sites = $this->cache->get('featured_unfold_sites', function (ItemInterface $item): array {
            $item->expiresAfter(900); // 15 minutes

            return $this->resolveSites();
        });
    }

    /**
     * @return array<int, array{subdomain: string, url: string, title: string, summary: ?string, image: ?string, coordinate: string}>
     */
    private function resolveSites(): array
    {
        $unfoldSites = $this->unfoldSiteRepository->findBy([], ['createdAt' => 'DESC']);

        if (empty($unfoldSites)) {
            return [];
        }

        $resolved = [];
        $host = rtrim($this->baseDomain, '/');

        foreach ($unfoldSites as $site) {
            $meta = $this->resolveMagazineMeta($site);
            if ($meta === null) {
                continue;
            }

            $resolved[] = [
                'subdomain' => $site->getSubdomain(),
                'url' => sprintf('https://%s.%s', $site->getSubdomain(), $host),
                'title' => $meta['title'],
                'summary' => $meta['summary'],
                'image' => $meta['image'],
                'coordinate' => $site->getCoordinate(),
            ];
        }

        return $resolved;
    }

    /**
     * @return array{title: string, summary: ?string, image: ?string}|null
     */
    private function resolveMagazineMeta(UnfoldSite $site): ?array
    {
        $coordinate = $site->getCoordinate();
        $parts = explode(':', $coordinate, 3);

        if (count($parts) !== 3) {
            $this->logger->warning('Invalid magazine coordinate on UnfoldSite', [
                'subdomain' => $site->getSubdomain(),
                'coordinate' => $coordinate,
            ]);
            return null;
        }

        [, $pubkey, $slug] = $parts;

        $events = $this->entityManager->getRepository(Event::class)->findBy([
            'kind' => KindsEnum::PUBLICATION_INDEX,
            'pubkey' => $pubkey,
        ]);

        $matching = array_filter($events, fn(Event $e) => $e->getSlug() === $slug);

        if (empty($matching)) {
            // Fallback: use subdomain as title when magazine event not yet cached
            return [
                'title' => ucfirst($site->getSubdomain()),
                'summary' => null,
                'image' => null,
            ];
        }

        usort($matching, fn(Event $a, Event $b) => $b->getCreatedAt() <=> $a->getCreatedAt());
        $magazine = array_shift($matching);

        return [
            'title' => $magazine->getTitle() ?: ucfirst($site->getSubdomain()),
            'summary' => $magazine->getSummary(),
            'image' => $magazine->getImage(),
        ];
    }
}

