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

        return $this->render('admin/analytics.html.twig', [
            'visitStats' => $visitStats,
            'last24hCount' => $last24hCount,
            'last7dCount' => $last7dCount,
            'uniqueVisitors24h' => $uniqueVisitors24h,
            'uniqueVisitors7d' => $uniqueVisitors7d,
            'totalUniqueVisitors' => $totalUniqueVisitors,
            'sessionStats' => $sessionStats,
        ]);
    }
}
