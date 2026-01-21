<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Service\AdminDashboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_ADMIN')]
class AdminDashboardController extends AbstractController
{
    public function __construct(
        private readonly AdminDashboardService $dashboardService
    ) {
    }

    #[Route('', name: 'dashboard')]
    public function index(): Response
    {
        $metrics = $this->dashboardService->getDashboardMetrics();

        return $this->render('admin/dashboard.html.twig', [
            'metrics' => $metrics,
        ]);
    }

    #[Route('/refresh', name: 'dashboard_refresh', methods: ['POST'])]
    public function refresh(): Response
    {
        $this->dashboardService->clearCache();
        $this->addFlash('success', 'Dashboard cache cleared and metrics refreshed.');

        return $this->redirectToRoute('admin_dashboard');
    }
}
