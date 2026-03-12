<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\NormalizedMedia;
use App\Service\Media\MediaProviderRegistry;
use App\Service\Media\MediaPublisher;
use App\Service\Media\MediaRelayQueryService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API endpoints for the multimedia manager.
 *
 * @see §11.3 of multimedia-manager spec
 */
#[Route('/api/media')]
class MediaManagerApiController extends AbstractController
{
    public function __construct(
        private readonly MediaProviderRegistry $providerRegistry,
        private readonly MediaRelayQueryService $relayQueryService,
        private readonly MediaPublisher $mediaPublisher,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * List all configured media providers.
     */
    #[Route('/providers', name: 'api_media_providers', methods: ['GET'])]
    public function providers(): JsonResponse
    {
        return new JsonResponse([
            'providers' => $this->providerRegistry->toArray(),
        ]);
    }

    /**
     * List uploaded assets from a provider.
     * Query params: provider, pubkey, cursor, limit
     */
    #[Route('/assets', name: 'api_media_assets', methods: ['GET'])]
    public function assets(Request $request): JsonResponse
    {
        $providerId = $request->query->get('provider');
        $pubkey = $request->query->get('pubkey', '');
        $cursor = $request->query->get('cursor');
        $limit = $request->query->getInt('limit', 50);

        if (!$providerId) {
            return new JsonResponse(['error' => 'Provider ID is required'], 400);
        }

        $provider = $this->providerRegistry->get($providerId);
        if (!$provider) {
            return new JsonResponse(['error' => 'Unknown provider: ' . $providerId], 404);
        }

        try {
            $assets = $provider->listAssets($pubkey, $cursor, min($limit, 100));

            return new JsonResponse([
                'provider' => $providerId,
                'assets' => array_map(fn(NormalizedMedia $a) => $a->toArray(), $assets),
                'count' => count($assets),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list assets', [
                'provider' => $providerId,
                'error' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Failed to list assets: ' . $e->getMessage()], 502);
        }
    }

    /**
     * List published media posts from relays.
     * Query params: pubkey, kinds (comma-separated), limit, filter
     */
    #[Route('/posts', name: 'api_media_posts', methods: ['GET'])]
    public function posts(Request $request): JsonResponse
    {
        $pubkey = $request->query->get('pubkey', '');
        $kindsStr = $request->query->get('kinds', '20,21,22');
        $limit = $request->query->getInt('limit', 50);
        $filter = $request->query->get('filter', 'all');

        $kinds = array_map('intval', explode(',', $kindsStr));

        try {
            if (!empty($pubkey)) {
                $posts = $this->relayQueryService->fetchPostsForAuthor($pubkey, $kinds, min($limit, 100));
            } else {
                $posts = $this->relayQueryService->fetchRecent(min($limit, 100));
            }

            $posts = $this->relayQueryService->filterByType($posts, $filter);

            return new JsonResponse([
                'posts' => array_map(fn(NormalizedMedia $p) => $p->toArray(), array_values($posts)),
                'count' => count($posts),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch media posts', [
                'error' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => 'Failed to fetch posts: ' . $e->getMessage()], 502);
        }
    }

    /**
     * Upload a file to a provider.
     */
    #[Route('/upload/{provider}', name: 'api_media_upload', methods: ['POST'])]
    public function upload(Request $request, string $provider): JsonResponse
    {
        $providerObj = $this->providerRegistry->get($provider);
        if (!$providerObj) {
            return new JsonResponse(['error' => 'Unknown provider: ' . $provider], 404);
        }

        if (!$providerObj->supportsUpload()) {
            return new JsonResponse(['error' => 'Provider does not support upload'], 400);
        }

        $authHeader = $request->headers->get('Authorization', '');
        if (!$authHeader || !str_starts_with($authHeader, 'Nostr ')) {
            return new JsonResponse(['error' => 'Missing or invalid NIP-98 Authorization header'], 401);
        }

        $file = $request->files->get('file');
        if (!$file) {
            return new JsonResponse(['error' => 'Missing file'], 400);
        }

        try {
            $asset = $providerObj->upload(
                $file->getPathname(),
                $file->getClientOriginalName() ?: ('upload_' . date('Ymd_His')),
                $file->getMimeType() ?: 'application/octet-stream',
                $authHeader,
            );

            return new JsonResponse([
                'status' => 'success',
                'asset' => $asset->toArray(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Upload failed', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);
            return new JsonResponse([
                'status' => 'error',
                'error' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Build an unsigned event draft for a picture (kind 20).
     */
    #[Route('/publish/picture', name: 'api_media_publish_picture', methods: ['POST'])]
    public function publishPicture(Request $request): JsonResponse
    {
        return $this->handlePublish($request, 20);
    }

    /**
     * Build an unsigned event draft for a video (kind 21).
     */
    #[Route('/publish/video', name: 'api_media_publish_video', methods: ['POST'])]
    public function publishVideo(Request $request): JsonResponse
    {
        return $this->handlePublish($request, 21);
    }

    /**
     * Build an unsigned event draft for a short video (kind 22).
     */
    #[Route('/publish/short-video', name: 'api_media_publish_short_video', methods: ['POST'])]
    public function publishShortVideo(Request $request): JsonResponse
    {
        return $this->handlePublish($request, 22);
    }

    /**
     * Delete an asset from a provider.
     */
    #[Route('/assets/{provider}/{hash}', name: 'api_media_delete', methods: ['DELETE'])]
    public function deleteAsset(Request $request, string $provider, string $hash): JsonResponse
    {
        $providerObj = $this->providerRegistry->get($provider);
        if (!$providerObj) {
            return new JsonResponse(['error' => 'Unknown provider'], 404);
        }

        if (!$providerObj->supportsDelete()) {
            return new JsonResponse(['error' => 'Provider does not support delete'], 400);
        }

        $authHeader = $request->headers->get('Authorization', '');
        if (!$authHeader) {
            return new JsonResponse(['error' => 'Authorization header required'], 401);
        }

        try {
            $success = $providerObj->deleteAsset($hash, $authHeader);
            return new JsonResponse([
                'status' => $success ? 'success' : 'error',
                'deleted' => $success,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 502);
        }
    }

    /**
     * Handle publish request for any kind (20, 21, 22).
     */
    private function handlePublish(Request $request, int $kind): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON body'], 400);
        }

        $pubkey = $data['pubkey'] ?? '';
        $title = $data['title'] ?? '';
        $content = $data['content'] ?? '';
        $mediaItems = $data['media_items'] ?? [];

        if (empty($title)) {
            return new JsonResponse(['error' => 'Title is required'], 400);
        }
        if (empty($mediaItems)) {
            return new JsonResponse(['error' => 'At least one media item is required'], 400);
        }

        // Convert raw media item data to NormalizedMedia objects
        $normalizedItems = [];
        foreach ($mediaItems as $item) {
            $normalizedItems[] = new NormalizedMedia(
                sourceType: 'asset',
                primaryUrl: $item['url'] ?? '',
                mime: $item['mime'] ?? null,
                sha256: $item['sha256'] ?? null,
                originalSha256: $item['original_sha256'] ?? null,
                size: isset($item['size']) ? (int) $item['size'] : null,
                dimensions: $item['dimensions'] ?? null,
                duration: isset($item['duration']) ? (float) $item['duration'] : null,
                bitrate: isset($item['bitrate']) ? (int) $item['bitrate'] : null,
                alt: $item['alt'] ?? null,
                blurhash: $item['blurhash'] ?? null,
                thumbUrl: $item['thumb_url'] ?? null,
                previewImages: $item['preview_images'] ?? [],
                fallbackUrls: $item['fallback_urls'] ?? [],
            );
        }

        $options = [
            'hashtags' => $data['hashtags'] ?? [],
            'alt' => $data['alt'] ?? null,
            'published_at' => $data['published_at'] ?? null,
            'content_warning' => $data['content_warning'] ?? null,
            'add_client_tag' => $data['add_client_tag'] ?? true,
        ];

        try {
            $draft = match ($kind) {
                20 => $this->mediaPublisher->buildPictureDraft($pubkey, $title, $content, $normalizedItems, $options),
                21 => $this->mediaPublisher->buildVideoDraft($pubkey, $title, $content, $normalizedItems, $options),
                22 => $this->mediaPublisher->buildShortVideoDraft($pubkey, $title, $content, $normalizedItems, $options),
                default => throw new \InvalidArgumentException('Unsupported kind: ' . $kind),
            };

            return new JsonResponse([
                'status' => 'success',
                'draft' => $draft,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to build media event draft', [
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }
}

