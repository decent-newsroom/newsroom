<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Entity\VanityName;
use App\Enum\VanityNamePaymentType;
use App\Enum\VanityNameStatus;
use App\Repository\VanityNameRepository;
use App\Service\VanityNameService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin controller for managing vanity names
 */
#[Route('/admin/vanity', name: 'admin_vanity_')]
#[IsGranted('ROLE_ADMIN')]
class VanityNameAdminController extends AbstractController
{
    public function __construct(
        private readonly VanityNameService $vanityNameService,
        private readonly VanityNameRepository $vanityNameRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * List all vanity names
     */
    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $statusFilter = $request->query->get('status');
        $searchQuery = $request->query->get('q', '');

        $status = null;
        if ($statusFilter !== null && $statusFilter !== '') {
            $status = VanityNameStatus::tryFrom($statusFilter);
        }

        if (!empty($searchQuery)) {
            $vanityNames = $this->vanityNameService->search($searchQuery);
        } else {
            $vanityNames = $this->vanityNameService->getAll($status);
        }

        return $this->render('admin/vanity/index.html.twig', [
            'vanityNames' => $vanityNames,
            'statuses' => VanityNameStatus::cases(),
            'currentStatus' => $statusFilter,
            'searchQuery' => $searchQuery,
            'serverDomain' => $this->vanityNameService->getServerDomain(),
        ]);
    }

    /**
     * Create vanity name form
     */
    #[Route('/create', name: 'create')]
    public function create(): Response
    {
        return $this->render('admin/vanity/create.html.twig', [
            'paymentTypes' => VanityNamePaymentType::cases(),
            'serverDomain' => $this->vanityNameService->getServerDomain(),
        ]);
    }

    /**
     * Store a new vanity name (admin can bypass payment)
     */
    #[Route('/store', name: 'store', methods: ['POST'])]
    public function store(Request $request): Response
    {
        $name = $request->request->get('vanity_name', '');
        $npub = $request->request->get('npub', '');
        $paymentTypeValue = $request->request->get('payment_type', 'admin_granted');

        // Validate CSRF
        if (!$this->isCsrfTokenValid('admin_vanity_create', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('admin_vanity_create');
        }

        // Validate npub format
        if (!str_starts_with($npub, 'npub1')) {
            $this->addFlash('error', 'Invalid npub format. Must start with npub1.');
            return $this->redirectToRoute('admin_vanity_create');
        }

        // Validate payment type
        $paymentType = VanityNamePaymentType::tryFrom($paymentTypeValue);
        if ($paymentType === null) {
            $this->addFlash('error', 'Invalid payment type.');
            return $this->redirectToRoute('admin_vanity_create');
        }

        try {
            $vanityName = $this->vanityNameService->reserve($npub, $name, $paymentType);

            // Admin grants are auto-activated, but for others we can also activate
            if ($paymentType !== VanityNamePaymentType::ADMIN_GRANTED) {
                $this->vanityNameService->activate($vanityName);
            }

            $this->addFlash('success', 'Vanity name "' . $name . '" created and activated.');
            return $this->redirectToRoute('admin_vanity_show', ['id' => $vanityName->getId()]);
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('admin_vanity_create');
        }
    }

    /**
     * Process expired vanity names
     */
    #[Route('/process-expired', name: 'process_expired', methods: ['POST'])]
    public function processExpired(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_process_expired', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('admin_vanity_index');
        }

        $count = $this->vanityNameService->processExpired();
        $this->addFlash('success', "Processed {$count} expired vanity name(s).");

        return $this->redirectToRoute('admin_vanity_index');
    }

    /**
     * View a single vanity name
     */
    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $vanityName = $this->vanityNameRepository->find($id);

        if ($vanityName === null) {
            throw $this->createNotFoundException('Vanity name not found.');
        }

        return $this->render('admin/vanity/show.html.twig', [
            'vanityName' => $vanityName,
            'serverDomain' => $this->vanityNameService->getServerDomain(),
        ]);
    }

    /**
     * Activate a pending vanity name
     */
    #[Route('/{id}/activate', name: 'activate', methods: ['POST'])]
    public function activate(int $id, Request $request): Response
    {
        $vanityName = $this->vanityNameRepository->find($id);

        if ($vanityName === null) {
            throw $this->createNotFoundException('Vanity name not found.');
        }

        if (!$this->isCsrfTokenValid('admin_vanity_action', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('admin_vanity_show', ['id' => $id]);
        }

        $this->vanityNameService->activate($vanityName);
        $this->addFlash('success', 'Vanity name activated.');

        return $this->redirectToRoute('admin_vanity_show', ['id' => $id]);
    }

    /**
     * Suspend a vanity name
     */
    #[Route('/{id}/suspend', name: 'suspend', methods: ['POST'])]
    public function suspend(int $id, Request $request): Response
    {
        $vanityName = $this->vanityNameRepository->find($id);

        if ($vanityName === null) {
            throw $this->createNotFoundException('Vanity name not found.');
        }

        if (!$this->isCsrfTokenValid('admin_vanity_action', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('admin_vanity_show', ['id' => $id]);
        }

        $this->vanityNameService->suspend($vanityName);
        $this->addFlash('success', 'Vanity name suspended.');

        return $this->redirectToRoute('admin_vanity_show', ['id' => $id]);
    }

    /**
     * Release a vanity name
     */
    #[Route('/{id}/release', name: 'release', methods: ['POST'])]
    public function release(int $id, Request $request): Response
    {
        $vanityName = $this->vanityNameRepository->find($id);

        if ($vanityName === null) {
            throw $this->createNotFoundException('Vanity name not found.');
        }

        if (!$this->isCsrfTokenValid('admin_vanity_action', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('admin_vanity_show', ['id' => $id]);
        }

        $this->vanityNameService->release($vanityName);
        $this->addFlash('success', 'Vanity name released.');

        return $this->redirectToRoute('admin_vanity_index');
    }
}




