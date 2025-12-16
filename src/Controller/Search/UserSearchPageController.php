<?php

namespace App\Controller\Search;

use App\Enum\RolesEnum;
use App\Service\Search\UserSearchInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class UserSearchPageController extends AbstractController
{
    public function __construct(
        private readonly UserSearchInterface $userSearch
    ) {
    }

    #[Route('/users/search', name: 'users_search_page', methods: ['GET'])]
    public function searchPage(Request $request): Response
    {
        $query = $request->query->get('q', '');
        $limit = min((int) $request->query->get('limit', 12), 100);
        $users = [];
        $resultsCount = 0;

        if (!empty(trim($query))) {
            $users = $this->userSearch->search($query, $limit);
            $resultsCount = count($users);
        }

        return $this->render('user_search/search.html.twig', [
            'query' => $query,
            'users' => $users,
            'resultsCount' => $resultsCount,
            'limit' => $limit,
        ]);
    }

    #[Route('/users/featured', name: 'featured_writers_page', methods: ['GET'])]
    public function featuredWritersPage(Request $request): Response
    {
        $query = $request->query->get('q');
        $limit = min((int) $request->query->get('limit', 12), 100);

        $users = $this->userSearch->findByRole(
            RolesEnum::FEATURED_WRITER->value,
            $query,
            $limit
        );

        return $this->render('user_search/featured_writers.html.twig', [
            'query' => $query,
            'users' => $users,
            'resultsCount' => count($users),
        ]);
    }
}

