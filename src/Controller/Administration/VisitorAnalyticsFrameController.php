<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Repository\VisitRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class VisitorAnalyticsFrameController extends AbstractController
{
    #[Route('/admin/analytics/visits', name: 'admin_analytics_visits_graph')]
    public function visitsGraph(): Response
    {
        return $this->render('admin/analytics_visits_graph.html.twig');
    }

    #[Route('/admin/analytics/visits/daily', name: 'admin_analytics_visits_graph_frame')]
    public function visitsGraphFrame(VisitRepository $visits): Response
    {
        // Query a short indexed window, then show the seven completed calendar days.
        $counts = [];
        foreach ($visits->getVisitsPerDay(8) as $row) {
            $counts[(new \DateTimeImmutable((string) $row['day']))->format('Y-m-d')] = (int) $row['count'];
        }

        $today = new \DateTimeImmutable('today');
        $dailyVisits = [];
        for ($daysAgo = 7; $daysAgo >= 1; --$daysAgo) {
            $day = $today->modify("-{$daysAgo} days")->format('Y-m-d');
            $dailyVisits[] = ['day' => $day, 'count' => $counts[$day] ?? 0];
        }

        return $this->render('admin/analytics_frames/visits_graph.html.twig', [
            'dailyVisits' => $dailyVisits,
        ]);
    }

    #[Route('/admin/analytics/bot/frame/{report}', name: 'admin_analytics_bot_frame', requirements: ['report' => 'summary|daily|agents'])]
    public function botFrame(string $report, VisitRepository $visits): Response
    {
        $data = match ($report) {
            'summary' => $visits->getBotVsHumanStats(),
            'daily' => $visits->getBotVisitsPerDay(14),
            'agents' => $visits->getTopBotUserAgents(20, new \DateTimeImmutable('-7 days')),
        };

        return $this->render('admin/analytics_frames/bot.html.twig', [
            'report' => $report,
            'data' => $data,
        ]);
    }

    #[Route('/admin/analytics/subdomains/frame/{report}', name: 'admin_analytics_subdomains_frame', requirements: ['report' => 'visits24|visits7|unique7|domains|daily|routes'])]
    public function subdomainFrame(string $report, VisitRepository $visits): Response
    {
        $data = match ($report) {
            'visits24' => $visits->countSubdomainVisitsSince(new \DateTimeImmutable('-24 hours')),
            'visits7' => $visits->countSubdomainVisitsSince(new \DateTimeImmutable('-7 days')),
            'unique7' => $visits->countUniqueSubdomainVisitorsSince(new \DateTimeImmutable('-7 days')),
            'domains' => $visits->getSubdomainVisitCounts(new \DateTimeImmutable('-30 days')),
            'daily' => $visits->getSubdomainVisitsPerDay(30),
            'routes' => $visits->getTopSubdomainRoutes(15, new \DateTimeImmutable('-7 days')),
        };

        return $this->render('admin/analytics_frames/subdomain.html.twig', [
            'report' => $report,
            'data' => $data,
        ]);
    }
}
