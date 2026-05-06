<?php

declare(strict_types=1);

namespace App\Controller\Administration;


use App\Message\FetchRelayInformationMessage;
use App\Service\Admin\RelayAdminService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/relay', name: 'admin_relay_')]
class RelayAdminController extends AbstractController
{
    public function __construct(
        private readonly RelayAdminService $relayAdminService,
        private readonly MessageBusInterface $bus,
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

    /**
     * NIP-11 Relay Information Document overview.
     */
    #[Route('/nip11', name: 'nip11_index', methods: ['GET'])]
    public function nip11Index(): Response
    {
        return $this->render('admin/relay/nip11.html.twig', [
            'relays' => $this->relayAdminService->getRelayInformationOverview(),
        ]);
    }

    /**
     * Queue a NIP-11 refresh for one or all relays. The work is dispatched
     * to the `async_low_priority` Messenger transport so the admin gets an
     * immediate redirect; the fetch happens in the background worker.
     */
    #[Route('/nip11/refresh', name: 'nip11_refresh', methods: ['POST'])]
    public function nip11Refresh(Request $request): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('relay_nip11_refresh', $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_relay_nip11_index');
        }

        $url = trim((string) $request->request->get('url', ''));
        if ($url !== '') {
            $this->bus->dispatch(new FetchRelayInformationMessage($url));
            $this->addFlash('success', sprintf('Queued NIP-11 refresh for %s.', $url));
            return $this->redirectToRoute('admin_relay_nip11_index');
        }

        // No URL → refresh every known relay
        $count = 0;
        foreach ($this->relayAdminService->getRelayInformationOverview() as $row) {
            $this->bus->dispatch(new FetchRelayInformationMessage($row['url']));
            $count++;
        }
        $this->addFlash('success', sprintf('Queued NIP-11 refresh for %d relays.', $count));
        return $this->redirectToRoute('admin_relay_nip11_index');
    }

    /**
     * NIP-66 relay directory.
     */
    #[Route('/directory', name: 'directory_index', methods: ['GET'])]
    public function directoryIndex(Request $request): Response
    {
        $filters = array_filter([
            'kind' => $request->query->get('kind') ? (int) $request->query->get('kind') : null,
            'nip'  => $request->query->get('nip')  ? (int) $request->query->get('nip')  : null,
        ]);

        return $this->render('admin/relay/directory.html.twig', [
            'relays'  => $this->relayAdminService->getRelayDirectory($filters),
            'filters' => $filters,
        ]);
    }

    /**
     * NIP-66 trusted monitor management.
     */
    #[Route('/monitors', name: 'monitors_index', methods: ['GET'])]
    public function monitorsIndex(): Response
    {
        return $this->render('admin/relay/monitors.html.twig', [
            'monitors' => $this->relayAdminService->getMonitors(),
        ]);
    }

    #[Route('/monitors/trust', name: 'monitors_trust', methods: ['POST'])]
    public function trustMonitor(Request $request): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('relay_monitors', $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_relay_monitors_index');
        }
        $pubkey = trim((string) $request->request->get('pubkey', ''));
        if ($pubkey === '') {
            $this->addFlash('error', 'No pubkey provided.');
            return $this->redirectToRoute('admin_relay_monitors_index');
        }
        $this->relayAdminService->trustMonitor($pubkey, $this->getUser()?->getId());
        $this->addFlash('success', sprintf('Trusted monitor: %s… (fetching their events in the background)', substr($pubkey, 0, 16)));
        return $this->redirectToRoute('admin_relay_monitors_index');
    }

    /**
     * Filter-shape statistics: typical filters and which take long to resolve.
     */
    #[Route('/filters', name: 'filters_index', methods: ['GET'])]
    public function filtersIndex(Request $request): Response
    {
        $sort = (string) ($request->query->get('sort') ?? 'count');
        $top  = max(1, min(200, (int) ($request->query->get('top') ?? 30)));
        $relayUrl = trim((string) $request->query->get('relay', ''));

        if ($relayUrl !== '') {
            return $this->render('admin/relay/filters_relay.html.twig', [
                'relay_url' => $relayUrl,
                'rows'      => $this->relayAdminService->getFilterStatsForRelay($relayUrl, $top, $sort),
                'sort'      => $sort,
                'top'       => $top,
            ]);
        }

        return $this->render('admin/relay/filters.html.twig', [
            'overview' => $this->relayAdminService->getFilterStatsOverview($top, $sort),
        ]);
    }

    #[Route('/monitors/untrust', name: 'monitors_untrust', methods: ['POST'])]
    public function untrustMonitor(Request $request): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('relay_monitors', $token)) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('admin_relay_monitors_index');
        }
        $pubkey = trim((string) $request->request->get('pubkey', ''));
        if ($pubkey === '') {
            $this->addFlash('error', 'No pubkey provided.');
            return $this->redirectToRoute('admin_relay_monitors_index');
        }
        if ($this->relayAdminService->untrustMonitor($pubkey)) {
            $this->addFlash('success', sprintf('Untrusted monitor: %s', substr($pubkey, 0, 16) . '…'));
        } else {
            $this->addFlash('error', 'Monitor not found.');
        }
        return $this->redirectToRoute('admin_relay_monitors_index');
    }
}

