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
    public function __construct(
        private readonly string $baseDomain,
    ) {}

    #[Route('/admin/analytics', name: 'admin_analytics')]
    #[IsGranted('ROLE_ADMIN')]
    public function index(VisitRepository $visitRepository): Response
    {
        // Time-bounded counters — all fast, indexed on visited_at
        $visitsLast24Hours = $visitRepository->countVisitsSince(new \DateTimeImmutable('-24 hours'));
        $visitsLast7Days = $visitRepository->countVisitsSince(new \DateTimeImmutable('-7 days'));
        $uniqueVisitorsLast24Hours = $visitRepository->countUniqueSessionsSince(new \DateTimeImmutable('-24 hours'));
        $uniqueVisitorsLast7Days = $visitRepository->countUniqueSessionsSince(new \DateTimeImmutable('-7 days'));

        // Referrers (time-bounded only — no all-time scan)
        $referredVisitsLast24Hours = $visitRepository->countVisitsWithReferer(new \DateTimeImmutable('-24 hours'));
        $referredVisitsLast7Days = $visitRepository->countVisitsWithReferer(new \DateTimeImmutable('-7 days'));
        $topReferersLast30Days = $visitRepository->getTopReferers(15, new \DateTimeImmutable('-30 days'));
        $topExternalReferersLast30Days = $visitRepository->getTopExternalReferers($this->baseDomain, 15, new \DateTimeImmutable('-30 days'));

        // Most read articles in the last 24 hrs
        $topArticlesLast24Hours = $visitRepository->getMostVisitedArticlesSince(new \DateTimeImmutable('-24 hours'), 5);

        // Time series — single native SQL query each
        $dailyVisitCountsLast30Days = $visitRepository->getVisitsPerDay(30);
        $dailyUniqueVisitorCountsLast7Days = $visitRepository->getDailyUniqueVisitors(7);

        // Article publish and zap stats (specific route filter — fast)
        $articlePublishStats = $visitRepository->getArticlePublishStats();
        $zapInvoiceStats = $visitRepository->getZapInvoiceStats();

        // Bot traffic summary (3 time-windowed counts)
        $botVsHumanStats = $visitRepository->getBotVsHumanStats();

        return $this->render('admin/analytics.html.twig', [
            'visitsLast24Hours' => $visitsLast24Hours,
            'visitsLast7Days' => $visitsLast7Days,
            'uniqueVisitorsLast24Hours' => $uniqueVisitorsLast24Hours,
            'uniqueVisitorsLast7Days' => $uniqueVisitorsLast7Days,
            'referredVisitsLast24Hours' => $referredVisitsLast24Hours,
            'referredVisitsLast7Days' => $referredVisitsLast7Days,
            'topReferersLast30Days' => $topReferersLast30Days,
            'topExternalReferersLast30Days' => $topExternalReferersLast30Days,
            'topArticlesLast24Hours' => $topArticlesLast24Hours,
            'dailyVisitCountsLast30Days' => $dailyVisitCountsLast30Days,
            'dailyUniqueVisitorCountsLast7Days' => $dailyUniqueVisitorCountsLast7Days,
            'articlePublishStats' => $articlePublishStats,
            'zapInvoiceStats' => $zapInvoiceStats,
            'botVsHumanStats' => $botVsHumanStats,
        ]);
    }

    #[Route('/admin/analytics/detail', name: 'admin_analytics_detail')]
    #[IsGranted('ROLE_ADMIN')]
    public function detailAnalytics(VisitRepository $visitRepository): Response
    {
        // All-time full-table scans — intentionally on a separate page
        $totalVisits = $visitRepository->getTotalVisits();
        $totalUniqueVisitors = $visitRepository->countUniqueVisitors();
        $totalReferredVisits = $visitRepository->countVisitsWithReferer();
        $averageVisitsPerSession = $visitRepository->getAverageVisitsPerSession();
        $bounceRate = $visitRepository->getBounceRate();

        // Top routes (all time) and route breakdown (7d)
        $topRoutesAllTime = $visitRepository->getMostPopularRoutes(5);
        $routeVisitCountsLast7Days = $visitRepository->getVisitCountByRoute(new \DateTimeImmutable('-7 days'));

        // Session detail
        $visitsBySessionLast7Days = $visitRepository->getVisitsBySession(new \DateTimeImmutable('-7 days'));

        // Recent raw visit records
        $recentVisitRecords = $visitRepository->getRecentVisits(10);

        return $this->render('admin/analytics_detail.html.twig', [
            'totalVisits' => $totalVisits,
            'totalUniqueVisitors' => $totalUniqueVisitors,
            'totalReferredVisits' => $totalReferredVisits,
            'averageVisitsPerSession' => $averageVisitsPerSession,
            'bounceRate' => $bounceRate,
            'topRoutesAllTime' => $topRoutesAllTime,
            'routeVisitCountsLast7Days' => $routeVisitCountsLast7Days,
            'visitsBySessionLast7Days' => $visitsBySessionLast7Days,
            'recentVisitRecords' => $recentVisitRecords,
        ]);
    }

    #[Route('/admin/analytics/bot', name: 'admin_analytics_bot')]
    #[IsGranted('ROLE_ADMIN')]
    public function botAnalytics(VisitRepository $visitRepository): Response
    {
        // Bot traffic statistics
        $botVsHumanStats = $visitRepository->getBotVsHumanStats();
        $topBotUserAgents = $visitRepository->getTopBotUserAgents(20, new \DateTimeImmutable('-7 days'));
        $botVisitsPerDayLast30Days = $visitRepository->getBotVisitsPerDay(30);

        return $this->render('admin/analytics_bot.html.twig', [
            'botVsHumanStats' => $botVsHumanStats,
            'topBotUserAgents' => $topBotUserAgents,
            'botVisitsPerDayLast30Days' => $botVisitsPerDayLast30Days,
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
        $recentSubdomainVisits = $visitRepository->getRecentSubdomainVisits(10);

        return $this->render('admin/analytics_subdomains.html.twig', [
            'subdomainVisitsLast24Hours' => $subdomainVisitsLast24Hours,
            'subdomainVisitsLast7Days' => $subdomainVisitsLast7Days,
            'totalSubdomainVisits' => $totalSubdomainVisits,
            'subdomainUniqueVisitorsLast7Days' => $subdomainUniqueVisitorsLast7Days,
            'subdomainVisitCountsLast30Days' => $subdomainVisitCountsLast30Days,
            'subdomainVisitsPerDayLast30Days' => $subdomainVisitsPerDayLast30Days,
            'topSubdomainRoutesLast7Days' => $topSubdomainRoutesLast7Days,
            'recentSubdomainVisits' => $recentSubdomainVisits,
        ]);
    }
}
