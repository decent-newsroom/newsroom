<?php

declare(strict_types=1);

namespace App\Controller\Editor;

use App\Entity\Article;
use App\Service\Cache\RedisCacheService;
use App\Service\Nostr\NostrClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class TranslationHelperController extends AbstractController
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly RedisCacheService $redisCacheService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Translation helper page — hidden route (not listed in navigation).
     */
    #[Route('/translation-helper', name: 'translation_helper')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        return $this->render('editor/translation_helper.html.twig');
    }

    /**
     * API: fetch the raw Nostr event for an article by naddr or coordinate.
     * Returns the full raw event JSON so the frontend can clone and modify tags.
     */
    #[Route('/api/translation/fetch-article', name: 'api_translation_fetch_article', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function fetchArticle(
        Request $request,
        EntityManagerInterface $em,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        $input = trim($data['input'] ?? '');

        if (empty($input)) {
            return $this->json(['error' => 'No naddr or coordinate provided.'], 400);
        }

        // Parse naddr if provided
        $coordinate = $input;
        if (str_starts_with($input, 'naddr1') || str_starts_with($input, 'nostr:naddr1')) {
            $naddr = preg_replace('/^nostr:/', '', $input);
            $coordinate = $this->parseNaddr($naddr);
            if (!$coordinate) {
                return $this->json(['error' => 'Invalid naddr format.'], 400);
            }
        }

        // Parse coordinate
        $parsed = $this->parseCoordinate($coordinate);
        if (!$parsed) {
            return $this->json(['error' => 'Invalid coordinate format. Expected kind:pubkey:slug'], 400);
        }

        // 1. Try local database first (fast path)
        $article = $em->getRepository(Article::class)->findOneBy([
            'pubkey' => $parsed['pubkey'],
            'slug'   => $parsed['slug'],
        ]);

        if ($article && $article->getRaw()) {
            $raw = $article->getRaw();

            return $this->json([
                'success'    => true,
                'source'     => 'database',
                'coordinate' => $coordinate,
                'event'      => $raw,
                'author'     => $this->resolveAuthorName($parsed['pubkey']),
            ]);
        }

        // 2. Fetch from relays
        try {
            $articlesMap = $this->nostrClient->getArticlesByCoordinates([$coordinate]);

            if (empty($articlesMap)) {
                return $this->json(['error' => 'Article not found on relays.'], 404);
            }

            $event = $articlesMap[$coordinate] ?? reset($articlesMap);

            if (!$event) {
                return $this->json(['error' => 'Article not found in relay response.'], 404);
            }

            // Convert event object to array
            $raw = json_decode(json_encode($event), true);

            return $this->json([
                'success'    => true,
                'source'     => 'relay',
                'coordinate' => $coordinate,
                'event'      => $raw,
                'author'     => $this->resolveAuthorName($parsed['pubkey']),
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Translation helper: failed to fetch article', [
                'coordinate' => $coordinate,
                'error'      => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'Failed to fetch article: ' . $e->getMessage(),
            ], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function parseNaddr(string $naddr): ?string
    {
        try {
            if (!str_starts_with($naddr, 'naddr1')) {
                return null;
            }

            $helper = new \swentel\nostr\Nip19\Nip19Helper();
            $decoded = $helper->decode($naddr);

            if (!isset($decoded['kind'], $decoded['author'], $decoded['identifier'])) {
                return null;
            }

            return sprintf('%d:%s:%s', $decoded['kind'], $decoded['author'], $decoded['identifier']);
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseCoordinate(string $coordinate): ?array
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3) {
            return null;
        }

        if (!is_numeric($parts[0])) {
            return null;
        }

        if (!preg_match('/^[a-f0-9]{64}$/i', $parts[1])) {
            return null;
        }

        return [
            'kind'   => (int) $parts[0],
            'pubkey' => $parts[1],
            'slug'   => $parts[2],
        ];
    }

    private function resolveAuthorName(string $pubkey): string
    {
        try {
            $metadata = $this->redisCacheService->getMetadata($pubkey);
            $name = $metadata->displayName ?: $metadata->name;
            if ($name) {
                return $name;
            }
        } catch (\Throwable) {
        }

        try {
            $key = new Key();
            $npub = $key->convertPublicKeyToBech32($pubkey);
            return substr($npub, 0, 12) . '…' . substr($npub, -4);
        } catch (\Throwable) {
            return 'Unknown';
        }
    }
}

