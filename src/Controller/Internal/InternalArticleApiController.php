<?php

declare(strict_types=1);

namespace App\Controller\Internal;

use App\Repository\ArticleRepository;
use App\Service\Internal\InternalArticlePresenter;
use App\Service\Search\ContentSearchService;
use App\Util\NostrKeyUtil;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Internal read-only article API consumed by the standalone MCP service.
 *
 * Access is restricted by App\EventSubscriber\InternalApiTokenSubscriber
 * (shared-secret header) and by network isolation — this prefix must not be
 * exposed through the public site configuration.
 *
 * All responses use the stable contract produced by InternalArticlePresenter.
 */
#[Route('/internal/api/articles')]
class InternalArticleApiController extends AbstractController
{
    private const MAX_LIMIT = 50;

    public function __construct(
        private readonly ContentSearchService $contentSearch,
        private readonly ArticleRepository $articleRepository,
        private readonly InternalArticlePresenter $presenter,
    ) {
    }

    #[Route('/search', name: 'internal_articles_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = trim((string) $request->query->get('q', ''));
        if ($query === '') {
            return $this->json(['results' => []]);
        }

        [$limit, $offset] = $this->pagination($request);
        $results = $this->contentSearch->search($query, $limit, $offset);

        return $this->json(['results' => $this->presenter->presentMany($results)]);
    }

    #[Route('/get', name: 'internal_articles_get', methods: ['GET'])]
    public function get(Request $request): JsonResponse
    {
        $coordinate = trim((string) $request->query->get('coordinate', ''));
        $parsed = $this->parseCoordinate($coordinate);
        if ($parsed === null) {
            return $this->json(['error' => 'Invalid coordinate. Expected kind:pubkey:slug'], 400);
        }

        $map = $this->articleRepository->findByCoordinates([$parsed]);
        $article = $map[$coordinate] ?? (count($map) > 0 ? reset($map) : null);

        if ($article === null) {
            return $this->json(['error' => 'Article not found', 'coordinate' => $coordinate], 404);
        }

        $item = $this->presenter->present($article, true);
        if ($item === null) {
            return $this->json(['error' => 'Article not presentable', 'coordinate' => $coordinate], 404);
        }

        return $this->json(['result' => $item]);
    }

    #[Route('/latest', name: 'internal_articles_latest', methods: ['GET'])]
    public function latest(Request $request): JsonResponse
    {
        [$limit] = $this->pagination($request);
        $results = $this->contentSearch->getLatest($limit);

        return $this->json(['results' => $this->presenter->presentMany($results)]);
    }

    #[Route('/by-author', name: 'internal_articles_by_author', methods: ['GET'])]
    public function byAuthor(Request $request): JsonResponse
    {
        $author = trim((string) $request->query->get('author', ''));
        if ($author === '') {
            return $this->json(['error' => 'Missing author'], 400);
        }

        try {
            $pubkeyHex = NostrKeyUtil::isNpub($author)
                ? NostrKeyUtil::npubToHex($author)
                : strtolower($author);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Invalid author: ' . $e->getMessage()], 400);
        }

        if (!NostrKeyUtil::isHexPubkey($pubkeyHex)) {
            return $this->json(['error' => 'Invalid author. Expected hex pubkey or npub'], 400);
        }

        [$limit, $offset] = $this->pagination($request);
        $results = $this->contentSearch->searchByAuthor($pubkeyHex, $limit, $offset);

        return $this->json(['results' => $this->presenter->presentMany($results)]);
    }

    #[Route('/by-topic', name: 'internal_articles_by_topic', methods: ['GET'])]
    public function byTopic(Request $request): JsonResponse
    {
        $topics = $this->parseTopics($request->query->get('topics', ''));
        if ($topics === []) {
            return $this->json(['results' => []]);
        }

        [$limit, $offset] = $this->pagination($request);
        $results = $this->contentSearch->searchByTopics($topics, $limit, $offset);

        return $this->json(['results' => $this->presenter->presentMany($results)]);
    }

    #[Route('/topics', name: 'internal_articles_topics', methods: ['GET'])]
    public function topics(Request $request): JsonResponse
    {
        $topics = $this->parseTopics($request->query->get('topics', ''));
        if ($topics === []) {
            return $this->json(['counts' => new \stdClass()]);
        }

        $counts = $this->contentSearch->getTopicsMetadata($topics);

        return $this->json(['counts' => $counts ?: new \stdClass()]);
    }

    /**
     * @return array{0:int,1:int} [limit, offset]
     */
    private function pagination(Request $request): array
    {
        $limit = (int) $request->query->get('limit', 12);
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $offset = max(0, (int) $request->query->get('offset', 0));

        return [$limit, $offset];
    }

    /**
     * @return array{kind:int,pubkey:string,slug:string}|null
     */
    private function parseCoordinate(string $coordinate): ?array
    {
        if ($coordinate === '') {
            return null;
        }

        // Split on the first two colons only; slugs may contain colons.
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3) {
            return null;
        }

        [$kind, $pubkey, $slug] = $parts;
        if (!ctype_digit($kind) || !NostrKeyUtil::isHexPubkey($pubkey) || $slug === '') {
            return null;
        }

        return ['kind' => (int) $kind, 'pubkey' => strtolower($pubkey), 'slug' => $slug];
    }

    /**
     * @return list<string>
     */
    private function parseTopics(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $topics = array_map('trim', explode(',', $raw));

        return array_values(array_filter($topics, static fn (string $t): bool => $t !== ''));
    }
}
