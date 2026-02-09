<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Entity\ActiveIndexingSubscription;
use App\Enum\ActiveIndexingStatus;
use App\Enum\ActiveIndexingTier;
use App\Repository\ActiveIndexingSubscriptionRepository;
use App\Service\ActiveIndexingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin controller for managing Active Indexing subscriptions
 */
#[Route('/admin/active-indexing', name: 'admin_active_indexing_')]
#[IsGranted('ROLE_ADMIN')]
class ActiveIndexingAdminController extends AbstractController
{
    public function __construct(
        private readonly ActiveIndexingService $activeIndexingService,
        private readonly ActiveIndexingSubscriptionRepository $subscriptionRepository,
    ) {
    }

    /**
     * List all Active Indexing subscriptions
     */
    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $statusFilter = $request->query->get('status');
        $searchQuery = $request->query->get('q', '');

        $status = null;
        if ($statusFilter !== null && $statusFilter !== '') {
            $status = ActiveIndexingStatus::tryFrom($statusFilter);
        }

        if (!empty($searchQuery)) {
            $subscriptions = $this->subscriptionRepository->searchByNpub($searchQuery);
        } elseif ($status !== null) {
            $subscriptions = $this->subscriptionRepository->findBy(['status' => $status], ['createdAt' => 'DESC']);
        } else {
            $subscriptions = $this->subscriptionRepository->findBy([], ['createdAt' => 'DESC']);
        }

        $statistics = $this->activeIndexingService->getStatistics();

        return $this->render('admin/active_indexing/index.html.twig', [
            'subscriptions' => $subscriptions,
            'statuses' => ActiveIndexingStatus::cases(),
            'currentStatus' => $statusFilter,
            'searchQuery' => $searchQuery,
            'statistics' => $statistics,
        ]);
    }

    /**
     * Create subscription form
     */
    #[Route('/create', name: 'create')]
    public function create(): Response
    {
        return $this->render('admin/active_indexing/create.html.twig', [
            'tiers' => ActiveIndexingTier::cases(),
        ]);
    }

    /**
     * Store a new subscription (admin can bypass payment)
     */
    #[Route('/store', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $npub = $request->request->get('npub', '');
        $tierValue = $request->request->get('tier', 'monthly');

        // Validate CSRF
        if (!$this->isCsrfTokenValid('admin_active_indexing_create', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('admin_active_indexing_create');
        }

        // Validate npub format
        if (!str_starts_with($npub, 'npub1')) {
            $this->addFlash('error', 'Invalid npub format. Must start with npub1.');
            return $this->redirectToRoute('admin_active_indexing_create');
        }

        // Validate tier
        $tier = ActiveIndexingTier::tryFrom($tierValue);
        if ($tier === null) {
            $this->addFlash('error', 'Invalid subscription tier.');
            return $this->redirectToRoute('admin_active_indexing_create');
        }

        try {
            // Check if already has a subscription
            $existing = $this->subscriptionRepository->findByNpub($npub);
            if ($existing && $existing->isActive()) {
                $this->addFlash('error', 'User already has an active subscription.');
                return $this->redirectToRoute('admin_active_indexing_show', ['id' => $existing->getId()]);
            }

            // Create and activate subscription
            $subscription = $this->activeIndexingService->getOrCreateSubscription($npub, $tier);
            $this->activeIndexingService->activateSubscription($subscription, 'admin_granted');

            $this->addFlash('success', 'Active Indexing subscription created and activated for ' . substr($npub, 0, 16) . '...');
            return $this->redirectToRoute('admin_active_indexing_show', ['id' => $subscription->getId()]);
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('admin_active_indexing_create');
        }
    }

    /**
     * Process expired subscriptions
     */
    #[Route('/process-expired', name: 'process_expired', methods: ['POST'])]
    public function processExpired(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_process_expired', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('admin_active_indexing_index');
        }

        $toGrace = $this->activeIndexingService->processExpiredToGrace();
        $graceEnded = $this->activeIndexingService->processGraceEnded();

        $this->addFlash('success', "Processed {$toGrace} subscription(s) to grace period, {$graceEnded} grace period(s) ended.");

        return $this->redirectToRoute('admin_active_indexing_index');
    }

    /**
     * View a single subscription
     */
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $subscription = $this->subscriptionRepository->find($id);

        if ($subscription === null) {
            throw $this->createNotFoundException('Subscription not found.');
        }

        return $this->render('admin/active_indexing/show.html.twig', [
            'subscription' => $subscription,
        ]);
    }

    /**
     * Activate a pending subscription
     */
    #[Route('/{id}/activate', name: 'activate', methods: ['POST'])]
    public function activate(int $id, Request $request): Response
    {
        $subscription = $this->subscriptionRepository->find($id);

        if ($subscription === null) {
            throw $this->createNotFoundException('Subscription not found.');
        }

        if (!$this->isCsrfTokenValid('admin_active_indexing_action', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('admin_active_indexing_show', ['id' => $id]);
        }

        $this->activeIndexingService->activateSubscription($subscription, 'admin_activated');
        $this->addFlash('success', 'Subscription activated.');

        return $this->redirectToRoute('admin_active_indexing_show', ['id' => $id]);
    }

    /**
     * Renew a subscription (extend duration)
     */
    #[Route('/{id}/renew', name: 'renew', methods: ['POST'])]
    public function renew(int $id, Request $request): Response
    {
        $subscription = $this->subscriptionRepository->find($id);

        if ($subscription === null) {
            throw $this->createNotFoundException('Subscription not found.');
        }

        if (!$this->isCsrfTokenValid('admin_active_indexing_action', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('admin_active_indexing_show', ['id' => $id]);
        }

        $this->activeIndexingService->renewSubscription($subscription, 'admin_renewed');
        $this->addFlash('success', 'Subscription renewed. New expiration: ' . $subscription->getExpiresAt()->format('Y-m-d'));

        return $this->redirectToRoute('admin_active_indexing_show', ['id' => $id]);
    }

    /**
     * Expire a subscription immediately
     */
    #[Route('/{id}/expire', name: 'expire', methods: ['POST'])]
    public function expire(int $id, Request $request): Response
    {
        $subscription = $this->subscriptionRepository->find($id);

        if ($subscription === null) {
            throw $this->createNotFoundException('Subscription not found.');
        }

        if (!$this->isCsrfTokenValid('admin_active_indexing_action', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('admin_active_indexing_show', ['id' => $id]);
        }

        $subscription->setStatus(ActiveIndexingStatus::EXPIRED);
        $this->subscriptionRepository->save($subscription);
        $this->addFlash('success', 'Subscription expired.');

        return $this->redirectToRoute('admin_active_indexing_show', ['id' => $id]);
    }

    /**
     * Delete a subscription
     */
    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $subscription = $this->subscriptionRepository->find($id);

        if ($subscription === null) {
            throw $this->createNotFoundException('Subscription not found.');
        }

        if (!$this->isCsrfTokenValid('admin_active_indexing_action', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('admin_active_indexing_show', ['id' => $id]);
        }

        $npub = $subscription->getNpub();
        $this->subscriptionRepository->remove($subscription);
        $this->addFlash('success', 'Subscription for ' . substr($npub, 0, 16) . '... deleted.');

        return $this->redirectToRoute('admin_active_indexing_index');
    }

    /**
     * Update subscription tier
     */
    #[Route('/{id}/update-tier', name: 'update_tier', methods: ['POST'])]
    public function updateTier(int $id, Request $request): Response
    {
        $subscription = $this->subscriptionRepository->find($id);

        if ($subscription === null) {
            throw $this->createNotFoundException('Subscription not found.');
        }

        if (!$this->isCsrfTokenValid('admin_active_indexing_action', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('admin_active_indexing_show', ['id' => $id]);
        }

        $tierValue = $request->request->get('tier');
        $tier = ActiveIndexingTier::tryFrom($tierValue);

        if ($tier === null) {
            $this->addFlash('error', 'Invalid tier.');
            return $this->redirectToRoute('admin_active_indexing_show', ['id' => $id]);
        }

        $subscription->setTier($tier);
        $this->subscriptionRepository->save($subscription);
        $this->addFlash('success', 'Subscription tier updated to ' . $tier->getLabel() . '.');

        return $this->redirectToRoute('admin_active_indexing_show', ['id' => $id]);
    }
}



