<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Enum\PublicationSubdomainStatus;
use App\Repository\PublicationSubdomainSubscriptionRepository;
use App\Service\PublicationSubdomainService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/publication-subdomain', name: 'admin_publication_subdomain_')]
#[IsGranted('ROLE_ADMIN')]
class PublicationSubdomainAdminController extends AbstractController
{
    public function __construct(
        private readonly PublicationSubdomainService $service,
        private readonly PublicationSubdomainSubscriptionRepository $repository,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $statusFilter = $request->query->get('status');
        $searchQuery = $request->query->get('q', '');

        $status = null;
        if ($statusFilter !== null && $statusFilter !== '') {
            $status = PublicationSubdomainStatus::tryFrom($statusFilter);
        }

        if (!empty($searchQuery)) {
            $subscriptions = $this->service->search($searchQuery);
        } else {
            $subscriptions = $this->service->getAll($status);
        }

        return $this->render('admin/publication_subdomain/index.html.twig', [
            'subscriptions' => $subscriptions,
            'statuses' => PublicationSubdomainStatus::cases(),
            'currentStatus' => $statusFilter,
            'searchQuery' => $searchQuery,
            'baseDomain' => $this->service->getBaseDomain(),
        ]);
    }

    #[Route('/{id}', name: 'show')]
    public function show(int $id): Response
    {
        $subscription = $this->repository->find($id);
        if (!$subscription) {
            throw $this->createNotFoundException('Subscription not found');
        }

        return $this->render('admin/publication_subdomain/show.html.twig', [
            'subscription' => $subscription,
            'baseDomain' => $this->service->getBaseDomain(),
        ]);
    }

    #[Route('/{id}/activate', name: 'activate', methods: ['POST'])]
    public function activate(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_publication_subdomain_action', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('admin_publication_subdomain_show', ['id' => $id]);
        }

        $subscription = $this->repository->find($id);
        if (!$subscription) {
            throw $this->createNotFoundException('Subscription not found');
        }

        try {
            $this->service->activateSubscription($subscription);
            $this->addFlash('success', 'Subscription activated successfully. Remember to manually configure DNS, proxy routing, and HTTPS for ' . $subscription->getSubdomain() . '.' . $this->service->getBaseDomain());
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to activate: ' . $e->getMessage());
        }

        return $this->redirectToRoute('admin_publication_subdomain_show', ['id' => $id]);
    }
}

