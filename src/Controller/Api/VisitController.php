<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Visit;
use App\Repository\VisitRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class VisitController extends AbstractController
{
    #[Route('/api/visit', name: 'api_record_visit', methods: ['POST'])]
    public function recordVisit(Request $request, VisitRepository $visitRepository): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['route']) || empty($data['route'])) {
            return new JsonResponse(['error' => 'Route is required'], Response::HTTP_BAD_REQUEST);
        }

        $route = $data['route'];
        $visit = new Visit($route);

        $visitRepository->save($visit);

        return new JsonResponse(['success' => true]);
    }
}
