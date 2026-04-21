<?php

namespace App\Twig\Components\Organisms;

use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Service\ArticleEventProjector;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\UserRelayListService;
use Psr\Log\LoggerInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class ArticleFromCoordinate
{
    public string $coordinate;
    public ?Article $article = null;
    public ?string $error = null;
    public array $authorsMetadata = [];
    public ?string $mag = null; // magazine slug (optional)
    public ?string $cat = null; // category slug (optional)

    /**
     * When true, if the article isn't in the local DB and the coordinate
     * kind is a longform article, attempt a synchronous relay fetch.
     * Keep default false — this flag should only be enabled on views that
     * render at most a handful of coordinates (e.g. the single-event page).
     */
    public bool $autoFetch = false;

    /** Parsed coordinate parts – available for the placeholder when article is not found */
    public ?string $parsedKind = null;
    public ?string $parsedPubkey = null;
    public ?string $parsedSlug = null;

    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly ?NostrClient $nostrClient = null,
        private readonly ?ArticleEventProjector $articleEventProjector = null,
        private readonly ?GenericEventProjector $genericEventProjector = null,
        private readonly ?UserRelayListService $userRelayListService = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function mount($coordinate, bool $autoFetch = false): void
    {
        $this->coordinate = $coordinate;
        $this->autoFetch = $autoFetch;
        // Parse coordinate (format: kind:pubkey:slug)
        $parts = explode(':', $this->coordinate, 3);

        if (count($parts) !== 3) {
            $this->error = 'Invalid coordinate format. Expected kind:pubkey:slug';
            return;
        }

        [$kind, $pubkey, $slug] = $parts;

        $this->parsedKind = $kind;
        $this->parsedPubkey = $pubkey;
        $this->parsedSlug = $slug;

        // Validate kind is numeric
        if (!is_numeric($kind)) {
            $this->error = 'Invalid kind value in coordinate';
            return;
        }

        $article = $this->lookupArticle($pubkey, $slug);

        // Best-effort sync fetch when the referenced article isn't in the DB.
        // Only runs for longform kinds that actually project to Article entities.
        if ($article === null
            && $this->autoFetch
            && $this->nostrClient !== null
            && $this->articleEventProjector !== null
            && $this->isLongformKind((int) $kind)
        ) {
            $article = $this->syncFetchArticle((int) $kind, $pubkey, $slug);
        }

        if ($article === null) {
            $this->error = 'Article not found';
            return;
        }

        $this->article = $article;
    }

    private function lookupArticle(string $pubkey, string $slug): ?Article
    {
        $qb = $this->articleRepository->createQueryBuilder('a');
        $qb->where('a.pubkey = :pubkey')
            ->andWhere('a.slug = :slug')
            ->setParameter('pubkey', $pubkey)
            ->setParameter('slug', $slug)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1);

        return $qb->getQuery()->getOneOrNullResult();
    }

    private function isLongformKind(int $kind): bool
    {
        return $kind === KindsEnum::LONGFORM->value
            || $kind === KindsEnum::LONGFORM_DRAFT->value;
    }

    private function syncFetchArticle(int $kind, string $pubkey, string $slug): ?Article
    {
        try {
            $relays = [];
            if ($this->userRelayListService !== null) {
                try {
                    $relays = $this->userRelayListService->getRelaysForFetching($pubkey);
                } catch (\Throwable $e) {
                    $this->logger?->warning('ArticleFromCoordinate: relay list lookup failed', [
                        'pubkey' => $pubkey,
                        'error'  => $e->getMessage(),
                    ]);
                }
            }

            $rawEvent = $this->nostrClient->getEventByNaddr([
                'kind'       => $kind,
                'pubkey'     => $pubkey,
                'identifier' => $slug,
                'relays'     => $relays,
            ]);

            if ($rawEvent === null) {
                return null;
            }

            $relaySource = $relays[0] ?? 'sync-coordinate-fetch';

            if ($this->genericEventProjector !== null) {
                try {
                    $this->genericEventProjector->projectEventFromNostrEvent($rawEvent, $relaySource);
                } catch (\Throwable $e) {
                    $this->logger?->warning('ArticleFromCoordinate: generic projection failed', [
                        'coordinate' => $this->coordinate,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }

            try {
                $this->articleEventProjector->projectArticleFromEvent($rawEvent, $relaySource);
            } catch (\Throwable $e) {
                $this->logger?->warning('ArticleFromCoordinate: article projection failed', [
                    'coordinate' => $this->coordinate,
                    'error'      => $e->getMessage(),
                ]);
                return null;
            }

            return $this->lookupArticle($pubkey, $slug);
        } catch (\Throwable $e) {
            $this->logger?->warning('ArticleFromCoordinate: sync fetch failed', [
                'coordinate' => $this->coordinate,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }
}
