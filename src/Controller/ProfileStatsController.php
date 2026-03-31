<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\VisitRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class ProfileStatsController extends AbstractController
{
    #[Route('/stats', name: 'profile_stats')]
    #[IsGranted('ROLE_ADMIN')]
    public function index(VisitRepository $visitRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $npub = $user->getUserIdentifier();

        // Total visits for different time periods
        $visitsLast24Hours = $visitRepository->countVisitsForNpubSince($npub, new \DateTimeImmutable('-24 hours'));
        $visitsLast7Days = $visitRepository->countVisitsForNpubSince($npub, new \DateTimeImmutable('-7 days'));
        $visitsLast30Days = $visitRepository->countVisitsForNpubSince($npub, new \DateTimeImmutable('-30 days'));

        // Unique visitors for different time periods
        $uniqueVisitorsLast24Hours = $visitRepository->countUniqueVisitorsForNpubSince($npub, new \DateTimeImmutable('-24 hours'));
        $uniqueVisitorsLast7Days = $visitRepository->countUniqueVisitorsForNpubSince($npub, new \DateTimeImmutable('-7 days'));
        $uniqueVisitorsLast30Days = $visitRepository->countUniqueVisitorsForNpubSince($npub, new \DateTimeImmutable('-30 days'));

        // Top articles
        $topArticlesLast7Days = $visitRepository->getMostVisitedArticlesForNpub($npub, new \DateTimeImmutable('-7 days'), 10);
        $topArticlesLast30Days = $visitRepository->getMostVisitedArticlesForNpub($npub, new \DateTimeImmutable('-30 days'), 10);

        // Visits per day (last 30 days) - sparse array, only days with visits
        $dailyVisitCountsRaw = $visitRepository->getVisitsPerDayForNpub($npub, 30);

        // Daily unique visitors (last 30 days) - full array with all days
        $dailyUniqueVisitors = $visitRepository->getDailyUniqueVisitorsForNpub($npub, 30);

        // Create a lookup map for visits by day
        $visitsMap = [];
        foreach ($dailyVisitCountsRaw as $row) {
            $visitsMap[$row['day']] = (int) $row['count'];
        }

        // Merge into aligned chart data using unique visitors days as the base (has all 30 days)
        $chartData = [];
        foreach ($dailyUniqueVisitors as $dayData) {
            $day = $dayData['day'];
            $chartData[] = [
                'day' => $day,
                'visits' => $visitsMap[$day] ?? 0,
                'uniqueVisitors' => (int) $dayData['count'],
            ];
        }

        // Visit breakdown (profile vs articles)
        $visitBreakdownLast7Days = $visitRepository->getVisitBreakdownForNpub($npub, new \DateTimeImmutable('-7 days'));
        $visitBreakdownLast30Days = $visitRepository->getVisitBreakdownForNpub($npub, new \DateTimeImmutable('-30 days'));

        return $this->render('stats/index.html.twig', [
            'visitsLast24Hours' => $visitsLast24Hours,
            'visitsLast7Days' => $visitsLast7Days,
            'visitsLast30Days' => $visitsLast30Days,
            'uniqueVisitorsLast24Hours' => $uniqueVisitorsLast24Hours,
            'uniqueVisitorsLast7Days' => $uniqueVisitorsLast7Days,
            'uniqueVisitorsLast30Days' => $uniqueVisitorsLast30Days,
            'topArticlesLast7Days' => $topArticlesLast7Days,
            'topArticlesLast30Days' => $topArticlesLast30Days,
            'chartData' => $chartData,
            'visitBreakdownLast7Days' => $visitBreakdownLast7Days,
            'visitBreakdownLast30Days' => $visitBreakdownLast30Days,
        ]);
    }
}


