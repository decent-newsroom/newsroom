<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Repository\VisitRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class VisitorAnalyticsController extends AbstractController
{
    #[Route('/admin/analytics', name: 'admin_analytics')]
    #[IsGranted('ROLE_ADMIN')]
    public function index(VisitRepository $visitRepository): Response
    {
        // Counters for the last 24 hours and last 7 days
        $visitsLast24Hours = $visitRepository->countVisitsSince(new \DateTimeImmutable('-24 hours'));
        $visitsLast7Days = $visitRepository->countVisitsSince(new \DateTimeImmutable('-7 days'));

        // Visits with HTTP referers
        $referredVisitsLast24Hours = $visitRepository->countVisitsWithReferer(new \DateTimeImmutable('-24 hours'));
        $referredVisitsLast7Days = $visitRepository->countVisitsWithReferer(new \DateTimeImmutable('-7 days'));
        $totalReferredVisits = $visitRepository->countVisitsWithReferer();
        $topReferersLast30Days = $visitRepository->getTopReferers(15, new \DateTimeImmutable('-30 days'));

        // Most read articles in the last 24 hrs
        $topArticlesLast24Hours = $visitRepository->getMostVisitedArticlesSince(new \DateTimeImmutable('-24 hours'), 5);

        // Visits by route for the last 7 days
        $routeVisitCountsLast7Days = $visitRepository->getVisitCountByRoute(new \DateTimeImmutable('-7 days'));

        // Unique visitors
        $uniqueVisitorsLast24Hours = $visitRepository->countUniqueSessionsSince(new \DateTimeImmutable('-24 hours'));
        $uniqueVisitorsLast7Days = $visitRepository->countUniqueSessionsSince(new \DateTimeImmutable('-7 days'));
        $totalUniqueVisitors = $visitRepository->countUniqueVisitors();

        // Visits by session for the last 7 days
        $visitsBySessionLast7Days = $visitRepository->getVisitsBySession(new \DateTimeImmutable('-7 days'));

        // Summary metrics
        $totalVisits = $visitRepository->getTotalVisits();
        $averageVisitsPerSession = $visitRepository->getAverageVisitsPerSession();
        $bounceRate = $visitRepository->getBounceRate();

        // Time series and top metrics
        $dailyVisitCountsLast30Days = $visitRepository->getVisitsPerDay(30);
        $topRoutesAllTime = $visitRepository->getMostPopularRoutes(5);
        $recentVisitRecords = $visitRepository->getRecentVisits(10);

        // Unique visitors per day for the last 7 days
        $dailyUniqueVisitorCountsLast7Days = $visitRepository->getDailyUniqueVisitors(7);

        // Article publish statistics
        $articlePublishStats = $visitRepository->getArticlePublishStats();

        // Zap invoice statistics
        $zapInvoiceStats = $visitRepository->getZapInvoiceStats();

        return $this->render('admin/analytics.html.twig', [
            'routeVisitCountsLast7Days' => $routeVisitCountsLast7Days,
            'visitsLast24Hours' => $visitsLast24Hours,
            'visitsLast7Days' => $visitsLast7Days,
            'referredVisitsLast24Hours' => $referredVisitsLast24Hours,
            'referredVisitsLast7Days' => $referredVisitsLast7Days,
            'totalReferredVisits' => $totalReferredVisits,
            'uniqueVisitorsLast24Hours' => $uniqueVisitorsLast24Hours,
            'uniqueVisitorsLast7Days' => $uniqueVisitorsLast7Days,
            'totalUniqueVisitors' => $totalUniqueVisitors,
            'visitsBySessionLast7Days' => $visitsBySessionLast7Days,
            'totalVisits' => $totalVisits,
            'averageVisitsPerSession' => $averageVisitsPerSession,
            'bounceRate' => $bounceRate,
            'dailyVisitCountsLast30Days' => $dailyVisitCountsLast30Days,
            'topRoutesAllTime' => $topRoutesAllTime,
            'topReferersLast30Days' => $topReferersLast30Days,
            'recentVisitRecords' => $recentVisitRecords,
            'dailyUniqueVisitorCountsLast7Days' => $dailyUniqueVisitorCountsLast7Days,
            'topArticlesLast24Hours' => $topArticlesLast24Hours,
            'articlePublishStats' => $articlePublishStats,
            'zapInvoiceStats' => $zapInvoiceStats,
        ]);
    }
}
