<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Service\Admin\MercureAdminService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/mercure', name: 'admin_mercure_')]
#[IsGranted('ROLE_ADMIN')]
class MercureAdminController extends AbstractController
{
    public function __construct(
        private readonly MercureAdminService $mercureAdminService,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $config = $this->mercureAdminService->getConfiguration();
        $connectivity = $this->mercureAdminService->testHubConnectivity();
        $subscriptions = $this->mercureAdminService->getActiveSubscriptions();
        $boltDb = $this->mercureAdminService->getBoltDbInfo();
        $knownTopics = $this->mercureAdminService->getKnownTopicPatterns();

        return $this->render('admin/mercure/index.html.twig', [
            'config' => $config,
            'connectivity' => $connectivity,
            'subscriptions' => $subscriptions,
            'bolt_db' => $boltDb,
            'known_topics' => $knownTopics,
        ]);
    }

    /**
     * AJAX endpoint: publish a test message to a Mercure topic.
     */
    #[Route('/test-publish', name: 'test_publish', methods: ['POST'])]
    public function testPublish(Request $request): JsonResponse
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('mercure_test', $token)) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }

        $topic = $request->request->get('topic', '/test/admin-ping');
        $topic = trim($topic);
        if ($topic === '') {
            $topic = '/test/admin-ping';
        }

        $result = $this->mercureAdminService->publishTest($topic);

        return $this->json($result);
    }

    /**
     * AJAX endpoint: refresh hub connectivity status.
     */
    #[Route('/test-connectivity', name: 'test_connectivity', methods: ['POST'])]
    public function testConnectivity(Request $request): JsonResponse
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('mercure_test', $token)) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }

        $connectivity = $this->mercureAdminService->testHubConnectivity();

        return $this->json($connectivity);
    }

    /**
     * AJAX endpoint: refresh active subscriptions.
     */
    #[Route('/subscriptions', name: 'subscriptions', methods: ['GET'])]
    public function subscriptions(): JsonResponse
    {
        $subscriptions = $this->mercureAdminService->getActiveSubscriptions();

        return $this->json($subscriptions);
    }
}

