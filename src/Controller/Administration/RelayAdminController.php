<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Service\Admin\RelayAdminService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
        $poolStatus = $this->relayAdminService->getPoolStatus();
        $workerHeartbeats = $this->relayAdminService->getWorkerHeartbeats();

        return $this->render('admin/relay/index.html.twig', [
            'stats' => $stats,
            'config' => $config,
            'container_status' => $containerStatus,
            'connectivity' => $connectivity,
            'recent_events' => $recentEvents,
            'pool_status' => $poolStatus,
            'worker_heartbeats' => $workerHeartbeats,
        ]);
    }

    #[Route('/gateway', name: 'gateway')]
    public function gatewayStatus(): Response
    {
        $status = $this->relayAdminService->getGatewayStatus();

        return $this->render('admin/relay/gateway.html.twig', [
            'status' => $status,
        ]);
    }

    #[Route('/gateway/mute', name: 'gateway_mute', methods: ['POST'])]
    public function muteRelay(Request $request): Response
    {
        $url = $request->request->get('url');
        $token = $request->request->get('_token');

        if (!$this->isCsrfTokenValid('relay_mute', $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_relay_gateway');
        }

        if (!$url) {
            $this->addFlash('error', 'No relay URL provided.');
            return $this->redirectToRoute('admin_relay_gateway');
        }

        if ($this->relayAdminService->muteRelay($url)) {
            $this->addFlash('success', sprintf('Relay muted: %s', $url));
        } else {
            $this->addFlash('error', 'Cannot mute the local relay.');
        }

        return $this->redirectToRoute('admin_relay_gateway');
    }

    #[Route('/gateway/unmute', name: 'gateway_unmute', methods: ['POST'])]
    public function unmuteRelay(Request $request): Response
    {
        $url = $request->request->get('url');
        $token = $request->request->get('_token');

        if (!$this->isCsrfTokenValid('relay_mute', $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_relay_gateway');
        }

        if (!$url) {
            $this->addFlash('error', 'No relay URL provided.');
            return $this->redirectToRoute('admin_relay_gateway');
        }

        $this->relayAdminService->unmuteRelay($url);
        $this->addFlash('success', sprintf('Relay unmuted: %s', $url));

        return $this->redirectToRoute('admin_relay_gateway');
    }

    #[Route('/gateway/reset', name: 'gateway_reset', methods: ['POST'])]
    public function resetRelayHealth(Request $request): Response
    {
        $url = $request->request->get('url');
        $token = $request->request->get('_token');

        if (!$this->isCsrfTokenValid('relay_mute', $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_relay_gateway');
        }

        if (!$url) {
            $this->addFlash('error', 'No relay URL provided.');
            return $this->redirectToRoute('admin_relay_gateway');
        }

        $this->relayAdminService->resetRelayHealth($url);
        $this->addFlash('success', sprintf('Health reset for: %s', $url));

        return $this->redirectToRoute('admin_relay_gateway');
    }

    #[Route('/pool', name: 'pool', methods: ['GET'])]
    public function poolStatus(): JsonResponse
    {
        return $this->json($this->relayAdminService->getPoolStatus());
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

