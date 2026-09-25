<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Repository\VisitRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class VisitRouteLookupController extends AbstractController
{
    #[Route('/admin/analytics/route-lookup', name: 'admin_analytics_route_lookup', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/analytics_route_lookup.html.twig');
    }

    #[Route('/admin/analytics/route-lookup/result', name: 'admin_analytics_route_lookup_result', methods: ['GET'])]
    public function result(Request $request, VisitRepository $visits): Response
    {
        $route = trim($request->query->getString('route'));
        $valid = $route !== ''
            && str_starts_with($route, '/')
            && mb_strlen($route) <= 255
            && !str_contains($route, '?')
            && !str_contains($route, '#')
            && preg_match('/[[:cntrl:]]/', $route) === 0;

        if (!$valid) {
            return $this->render('admin/_analytics_route_lookup_result.html.twig', [
                'error' => true,
                'route' => $route,
            ]);
        }

        $today = new \DateTimeImmutable('today');
        $since = $today->modify('-6 days');
        $rows = $visits->getRawVisitCountsByExactRouteBetween($route, $since, $since->modify('+7 days'));
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[$row['day']] = (int) $row['count'];
        }

        $daily = [];
        $total = 0;
        for ($day = $since; $day <= $today; $day = $day->modify('+1 day')) {
            $count = $byDay[$day->format('Y-m-d')] ?? 0;
            $daily[] = ['day' => $day, 'count' => $count];
            $total += $count;
        }

        return $this->render('admin/_analytics_route_lookup_result.html.twig', [
            'error' => false,
            'route' => $route,
            'daily' => $daily,
            'total' => $total,
            'since' => $since,
        ]);
    }
}