<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\VanityNameService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller for NIP-05 well-known endpoint
 * Serves /.well-known/nostr.json for vanity name verification
 */
class WellKnownController extends AbstractController
{
    public function __construct(
        private readonly VanityNameService $vanityNameService,
    ) {
    }

    /**
     * NIP-05 well-known endpoint
     * Returns JSON with names mapping to hex pubkeys
     *
     * IMPORTANT: This endpoint MUST NOT return HTTP redirects per NIP-05 spec
     */
    #[Route('/.well-known/nostr.json', name: 'well_known_nostr', methods: ['GET'])]
    public function nostrJson(Request $request): Response
    {
        // Get optional name parameter for single lookup
        $name = $request->query->get('name');

        // Get the NIP-05 response data
        $data = $this->vanityNameService->getNip05Response($name);

        // Create JSON response with proper CORS headers
        $response = new JsonResponse($data);

        // Required CORS header for NIP-05 compliance
        $response->headers->set('Access-Control-Allow-Origin', '*');

        // Cache control - allow browser and CDN caching
        $response->headers->set('Cache-Control', 'public, max-age=300');

        // Content type
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    /**
     * CORS preflight handler for well-known endpoint
     */
    #[Route('/.well-known/nostr.json', name: 'well_known_nostr_options', methods: ['OPTIONS'])]
    public function nostrJsonOptions(): Response
    {
        $response = new Response('', Response::HTTP_NO_CONTENT);
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept');
        $response->headers->set('Access-Control-Max-Age', '86400'); // 24 hours

        return $response;
    }
}

