<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Service\RelayAdminService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/relay', name: 'admin_relay_')]
class RelayAdminController extends AbstractController
{
    public function __construct(
        private readonly RelayAdminService $relayAdminService
    ) {
    }

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $stats = $this->relayAdminService->getStats();
        $config = $this->relayAdminService->getConfiguration();
        $containerStatus = $this->relayAdminService->getContainerStatus();
        $connectivity = $this->relayAdminService->testConnectivity();
        $recentEvents = $this->relayAdminService->getRecentEvents(50);

        return $this->render('admin/relay/index.html.twig', [
            'stats' => $stats,
            'config' => $config,
            'container_status' => $containerStatus,
            'connectivity' => $connectivity,
            'recent_events' => $recentEvents,
        ]);
    }

    #[Route('/stats', name: 'stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        return $this->json($this->relayAdminService->getStats());
    }

    #[Route('/events', name: 'events', methods: ['GET'])]
    public function events(): JsonResponse
    {
        $events = $this->relayAdminService->getRecentEvents(20);
        return $this->json($events);
    }

    #[Route('/logs', name: 'logs', methods: ['GET'])]
    public function logs(): Response
    {
        $logs = $this->relayAdminService->getSyncLogs(100);

        return new Response($logs, 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    #[Route('/sync', name: 'sync', methods: ['POST'])]
    public function triggerSync(): JsonResponse
    {
        $result = $this->relayAdminService->triggerSync();
        return $this->json($result);
    }

    #[Route('/status', name: 'status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return $this->json([
            'containers' => $this->relayAdminService->getContainerStatus(),
            'connectivity' => $this->relayAdminService->testConnectivity(),
            'config' => $this->relayAdminService->getConfiguration(),
        ]);
    }
}

