<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Repository\VisitRepository;
use App\Service\Admin\AdminDashboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class VisitorAnalyticsController extends AbstractController
{
    #[Route('/admin/analytics', name: 'admin_analytics')]
    #[IsGranted('ROLE_ADMIN')]
    public function index(AdminDashboardService $dashboardService): Response
    {
        return $this->render('admin/analytics.html.twig', [
            'snapshot' => $dashboardService->getVisitStats(),
        ]);
    }

    #[Route('/admin/analytics/detail', name: 'admin_analytics_detail')]
    #[IsGranted('ROLE_ADMIN')]
    public function detailAnalytics(): Response
    {
        return $this->render('admin/analytics_detail.html.twig');
    }

    #[Route('/admin/analytics/detail/{metric}', name: 'admin_analytics_detail_metric', requirements: ['metric' => 'visits|visitors|average|bounce|sessions'])]
    #[IsGranted('ROLE_ADMIN')]
    public function detailMetric(string $metric, VisitRepository $visitRepository): Response
    {
        $since30 = new \DateTimeImmutable('-30 days');
        $value = match ($metric) {
            'visits' => $visitRepository->getAdminDetailSampleMetric('visits', $since30),
            'visitors' => $visitRepository->getAdminDetailSampleMetric('visitors', $since30),
            'average' => $visitRepository->getAdminDetailSampleMetric('average', $since30),
            'bounce' => $visitRepository->getAdminDetailSampleMetric('bounce', $since30),
            'sessions' => $visitRepository->getRecentSessionsFromSample(new \DateTimeImmutable('-7 days')),
        };

        return $this->render('admin/analytics/_detail_metric.html.twig', [
            'metric' => $metric,
            'value' => $value,
        ]);
    }

    #[Route('/admin/analytics/bot', name: 'admin_analytics_bot')]
    #[IsGranted('ROLE_ADMIN')]
    public function botAnalytics(): Response
    {
        return $this->render('admin/analytics_bot.html.twig');
    }

    #[Route('/admin/analytics/subdomains', name: 'admin_analytics_subdomains')]
    #[IsGranted('ROLE_ADMIN')]
    public function subdomainAnalytics(): Response
    {
        return $this->render('admin/analytics_subdomains.html.twig');
    }
}
