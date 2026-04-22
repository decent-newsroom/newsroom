<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\WebPreviewService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * On-demand web preview endpoint.
 *
 * Separate from WebPreviewService so we never auto-fetch arbitrary external
 * URLs from comment tags — the user explicitly opts in by clicking the
 * "Load preview" CTA on the comment page, which calls this endpoint.
 */
final class WebPreviewController extends AbstractController
{
    public function __construct(
        private readonly WebPreviewService $webPreviewService,
    ) {
    }

    #[Route('/api/web-preview', name: 'api_web_preview', methods: ['GET'])]
    public function fetch(Request $request): JsonResponse
    {
        $url = (string) $request->query->get('url', '');
        if ($url === '') {
            return new JsonResponse(['error' => 'missing url'], Response::HTTP_BAD_REQUEST);
        }
        if (!preg_match('#^https?://#i', $url)) {
            return new JsonResponse(['error' => 'unsupported scheme'], Response::HTTP_BAD_REQUEST);
        }

        $preview = $this->webPreviewService->fetch($url);
        if ($preview === null) {
            return new JsonResponse(['error' => 'not previewable'], Response::HTTP_BAD_REQUEST);
        }

        $response = new JsonResponse($preview);
        // Allow short-term browser caching; service already caches server-side.
        $response->setPublic();
        $response->setMaxAge(900);
        $response->setSharedMaxAge(900);
        return $response;
    }
}

