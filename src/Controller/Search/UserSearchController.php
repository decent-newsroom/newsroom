<?php

namespace App\Controller\Search;

use App\Enum\RolesEnum;
use App\Service\Search\UserSearchInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/users')]
class UserSearchController extends AbstractController
{
    public function __construct(
        private readonly UserSearchInterface $userSearch
    ) {
    }

    /**
     * Search users by query string
     * GET /api/users/search?q=query&limit=20&offset=0
     */
    #[Route('/search', name: 'api_users_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        $limit = min((int) $request->query->get('limit', 12), 100);
        $offset = max((int) $request->query->get('offset', 0), 0);

        if (empty(trim($query))) {
            return $this->json([
                'error' => 'Query parameter "q" is required',
                'users' => []
            ], Response::HTTP_BAD_REQUEST);
        }

        $users = $this->userSearch->search($query, $limit, $offset);

        return $this->json([
            'query' => $query,
            'count' => count($users),
            'limit' => $limit,
            'offset' => $offset,
            'users' => array_map(fn($user) => [
                'id' => $user->getId(),
                'npub' => $user->getNpub(),
                'displayName' => $user->getDisplayName(),
                'name' => $user->getName(),
                'nip05' => $user->getNip05(),
                'about' => $user->getAbout(),
                'picture' => $user->getPicture(),
                'website' => $user->getWebsite(),
                'lud16' => $user->getLud16(),
            ], $users)
        ]);
    }

    /**
     * Get featured writers with optional search
     * GET /api/users/featured-writers?q=query&limit=12
     */
    #[Route('/featured-writers', name: 'api_users_featured_writers', methods: ['GET'])]
    public function featuredWriters(Request $request): JsonResponse
    {
        $query = $request->query->get('q');
        $limit = min((int) $request->query->get('limit', 12), 100);
        $offset = max((int) $request->query->get('offset', 0), 0);

        $users = $this->userSearch->findByRole(
            RolesEnum::FEATURED_WRITER->value,
            $query,
            $limit,
            $offset
        );

        return $this->json([
            'query' => $query,
            'count' => count($users),
            'limit' => $limit,
            'offset' => $offset,
            'users' => array_map(fn($user) => [
                'id' => $user->getId(),
                'npub' => $user->getNpub(),
                'displayName' => $user->getDisplayName(),
                'name' => $user->getName(),
                'nip05' => $user->getNip05(),
                'about' => $user->getAbout(),
                'picture' => $user->getPicture(),
                'website' => $user->getWebsite(),
                'lud16' => $user->getLud16(),
            ], $users)
        ]);
    }

    /**
     * Find users by their npubs
     * POST /api/users/by-npubs
     * Body: {"npubs": ["npub1...", "npub2..."]}
     */
    #[Route('/by-npubs', name: 'api_users_by_npubs', methods: ['POST'])]
    public function byNpubs(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $npubs = $data['npubs'] ?? [];

        if (empty($npubs) || !is_array($npubs)) {
            return $this->json([
                'error' => 'Field "npubs" is required and must be an array',
                'users' => []
            ], Response::HTTP_BAD_REQUEST);
        }

        $limit = min((int) ($data['limit'] ?? 200), 200);
        $users = $this->userSearch->findByNpubs($npubs, $limit);

        return $this->json([
            'count' => count($users),
            'users' => array_map(fn($user) => [
                'id' => $user->getId(),
                'npub' => $user->getNpub(),
                'displayName' => $user->getDisplayName(),
                'name' => $user->getName(),
                'nip05' => $user->getNip05(),
                'about' => $user->getAbout(),
                'picture' => $user->getPicture(),
                'website' => $user->getWebsite(),
                'lud16' => $user->getLud16(),
            ], $users)
        ]);
    }
}

