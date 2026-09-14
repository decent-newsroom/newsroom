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
    public function detailAnalytics(VisitRepository $visitRepository): Response
    {
        $since30 = new \DateTimeImmutable('-30 days');
        $since7  = new \DateTimeImmutable('-7 days');

        // All counts capped to last 30 days — no full-table scans
        $totalVisitsLast30 = $visitRepository->countVisitsSince($since30);
        $totalUniqueVisitorsLast30 = $visitRepository->countUniqueSessionsSince($since30);
        $bounceRate = $visitRepository->getBounceRateSince($since30);
        $averageVisitsPerSession = $visitRepository->getAverageVisitsPerSessionSince($since30);

        // Session detail (7d)
        $visitsBySessionLast7Days = $visitRepository->getVisitsBySession($since7);

        return $this->render('admin/analytics_detail.html.twig', [
            'totalVisitsLast30' => $totalVisitsLast30,
            'totalUniqueVisitorsLast30' => $totalUniqueVisitorsLast30,
            'averageVisitsPerSession' => $averageVisitsPerSession,
            'bounceRate' => $bounceRate,
            'visitsBySessionLast7Days' => $visitsBySessionLast7Days,
        ]);
    }

    #[Route('/admin/analytics/bot', name: 'admin_analytics_bot')]
    #[IsGranted('ROLE_ADMIN')]
    public function botAnalytics(VisitRepository $visitRepository): Response
    {
        // Bot traffic statistics
        $botVsHumanStats = $visitRepository->getBotVsHumanStats();
        $topBotUserAgents = $visitRepository->getTopBotUserAgents(20, new \DateTimeImmutable('-7 days'));
        $botVisitsPerDayLast14Days = $visitRepository->getBotVisitsPerDay(14);

        return $this->render('admin/analytics_bot.html.twig', [
            'botVsHumanStats' => $botVsHumanStats,
            'topBotUserAgents' => $topBotUserAgents,
            'botVisitsPerDayLast14Days' => $botVisitsPerDayLast14Days,
        ]);
    }

    #[Route('/admin/analytics/subdomains', name: 'admin_analytics_subdomains')]
    #[IsGranted('ROLE_ADMIN')]
    public function subdomainAnalytics(VisitRepository $visitRepository): Response
    {
        // Subdomain analytics
        $subdomainVisitsLast24Hours = $visitRepository->countSubdomainVisitsSince(new \DateTimeImmutable('-24 hours'));
        $subdomainVisitsLast7Days = $visitRepository->countSubdomainVisitsSince(new \DateTimeImmutable('-7 days'));
        $totalSubdomainVisits = $visitRepository->countTotalSubdomainVisits();
        $subdomainUniqueVisitorsLast7Days = $visitRepository->countUniqueSubdomainVisitorsSince(new \DateTimeImmutable('-7 days'));
        $subdomainVisitCountsLast30Days = $visitRepository->getSubdomainVisitCounts(new \DateTimeImmutable('-30 days'));
        $subdomainVisitsPerDayLast30Days = $visitRepository->getSubdomainVisitsPerDay(30);
        $topSubdomainRoutesLast7Days = $visitRepository->getTopSubdomainRoutes(15, new \DateTimeImmutable('-7 days'));

        return $this->render('admin/analytics_subdomains.html.twig', [
            'subdomainVisitsLast24Hours' => $subdomainVisitsLast24Hours,
            'subdomainVisitsLast7Days' => $subdomainVisitsLast7Days,
            'totalSubdomainVisits' => $totalSubdomainVisits,
            'subdomainUniqueVisitorsLast7Days' => $subdomainUniqueVisitorsLast7Days,
            'subdomainVisitCountsLast30Days' => $subdomainVisitCountsLast30Days,
            'subdomainVisitsPerDayLast30Days' => $subdomainVisitsPerDayLast30Days,
            'topSubdomainRoutesLast7Days' => $topSubdomainRoutesLast7Days,
        ]);
    }
}
