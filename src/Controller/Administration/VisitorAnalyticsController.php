<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Repository\VisitRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class VisitorAnalyticsController extends AbstractController
{
    #[Route('/admin/analytics', name: 'admin_analytics')]
    public function index(VisitRepository $visitRepository): Response
    {
        $visitStats = $visitRepository->getVisitCountByRoute();

        return $this->render('admin/analytics.html.twig', [
            'visitStats' => $visitStats,
        ]);
    }
}
