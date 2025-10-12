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
        $last24h = new \DateTimeImmutable('-24 hours');
        $last7d = new \DateTimeImmutable('-7 days');

        $visitStats = $visitRepository->getVisitCountByRoute($last7d);

        $last24hCount = $visitRepository->countVisitsSince($last24h);
        $last7dCount = $visitRepository->countVisitsSince($last7d);

        // Unique session tracking
        $uniqueVisitors24h = $visitRepository->countUniqueSessionsSince($last24h);
        $uniqueVisitors7d = $visitRepository->countUniqueSessionsSince($last7d);
        $totalUniqueVisitors = $visitRepository->countUniqueVisitors();

        // Session-based visit breakdown
        $sessionStats = $visitRepository->getVisitsBySession($last7d);

        // New metrics for improved dashboard
        $totalVisits = $visitRepository->getTotalVisits();
        $avgVisitsPerSession = $visitRepository->getAverageVisitsPerSession();
        $bounceRate = $visitRepository->getBounceRate();
        $visitsPerDay = $visitRepository->getVisitsPerDay(30);
        $mostPopularRoutes = $visitRepository->getMostPopularRoutes(5);
        $recentVisits = $visitRepository->getRecentVisits(10);

        // Calculate unique visitors per day for the last 7 days
        $uniqueVisitorsPerDay = [];
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
            $uniqueVisitorsPerDay[] = [
                'day' => $day->format('Y-m-d'),
                'count' => $count
            ];
        }

        return $this->render('admin/analytics.html.twig', [
            'visitStats' => $visitStats,
            'last24hCount' => $last24hCount,
            'last7dCount' => $last7dCount,
            'uniqueVisitors24h' => $uniqueVisitors24h,
            'uniqueVisitors7d' => $uniqueVisitors7d,
            'totalUniqueVisitors' => $totalUniqueVisitors,
            'sessionStats' => $sessionStats,
            // New variables for dashboard
            'totalVisits' => $totalVisits,
            'avgVisitsPerSession' => $avgVisitsPerSession,
            'bounceRate' => $bounceRate,
            'visitsPerDay' => $visitsPerDay,
            'mostPopularRoutes' => $mostPopularRoutes,
            'recentVisits' => $recentVisits,
            'uniqueVisitorsPerDay' => $uniqueVisitorsPerDay,
        ]);
    }
}
