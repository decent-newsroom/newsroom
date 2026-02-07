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
        $dailyUniqueVisitorCountsLast7Days = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = (new \DateTimeImmutable("today"))->modify("-{$i} days");
            $start = $day->setTime(0, 0, 0);
            $end = $day->setTime(23, 59, 59);
            $qb = $visitRepository->createQueryBuilder('v');
            $qb->select('COUNT(DISTINCT v.sessionId)')
                ->where('v.visitedAt BETWEEN :start AND :end')
                ->andWhere('v.sessionId IS NOT NULL')
                ->setParameter('start', $start)
                ->setParameter('end', $end);
            $count = (int) $qb->getQuery()->getSingleScalarResult();
            $dailyUniqueVisitorCountsLast7Days[] = [
                'day' => $day->format('Y-m-d'),
                'count' => $count
            ];
        }

        // Article publish statistics
        $articlePublishStats = $visitRepository->getArticlePublishStats();

        // Zap invoice statistics
        $zapInvoiceStats = $visitRepository->getZapInvoiceStats();

        return $this->render('admin/analytics.html.twig', [
            'routeVisitCountsLast7Days' => $routeVisitCountsLast7Days,
            'visitsLast24Hours' => $visitsLast24Hours,
            'visitsLast7Days' => $visitsLast7Days,
            'uniqueVisitorsLast24Hours' => $uniqueVisitorsLast24Hours,
            'uniqueVisitorsLast7Days' => $uniqueVisitorsLast7Days,
            'totalUniqueVisitors' => $totalUniqueVisitors,
            'visitsBySessionLast7Days' => $visitsBySessionLast7Days,
            'totalVisits' => $totalVisits,
            'averageVisitsPerSession' => $averageVisitsPerSession,
            'bounceRate' => $bounceRate,
            'dailyVisitCountsLast30Days' => $dailyVisitCountsLast30Days,
            'topRoutesAllTime' => $topRoutesAllTime,
            'recentVisitRecords' => $recentVisitRecords,
            'dailyUniqueVisitorCountsLast7Days' => $dailyUniqueVisitorCountsLast7Days,
            'topArticlesLast24Hours' => $topArticlesLast24Hours,
            'articlePublishStats' => $articlePublishStats,
            'zapInvoiceStats' => $zapInvoiceStats,
        ]);
    }
}
