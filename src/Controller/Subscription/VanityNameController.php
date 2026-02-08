<?php

declare(strict_types=1);

namespace App\Controller\Subscription;

use App\Enum\VanityNamePaymentType;
use App\Repository\VanityNameRepository;
use App\Service\QRGenerator;
use App\Service\VanityNameService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller for vanity name registration and management
 */
#[Route('/subscription/vanity', name: 'vanity_')]
class VanityNameController extends AbstractController
{
    public function __construct(
        private readonly VanityNameService $vanityNameService,
        private readonly VanityNameRepository $vanityNameRepository,
        private readonly QRGenerator $qrGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Vanity name registration page
     */
    #[Route('', name: 'index')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $npub = $this->getUser()?->getUserIdentifier();
        $existingVanity = $this->vanityNameService->getByNpub($npub);

        return $this->render('subscription/vanity/index.html.twig', [
            'existingVanity' => $existingVanity,
            'paymentTypes' => [
                VanityNamePaymentType::SUBSCRIPTION,
                VanityNamePaymentType::ONE_TIME,
            ],
            'serverDomain' => $this->vanityNameService->getServerDomain(),
        ]);
    }

    /**
     * Check availability of a vanity name (AJAX)
     */
    #[Route('/check', name: 'check', methods: ['GET'])]
    public function checkAvailability(Request $request): JsonResponse
    {
        $name = $request->query->get('name', '');

        if (empty($name)) {
            return $this->json(['available' => false, 'error' => 'Name is required']);
        }

        $error = $this->vanityNameService->getValidationError($name);

        return $this->json([
            'available' => $error === null,
            'error' => $error,
            'name' => strtolower($name),
        ]);
    }

    /**
     * Register a vanity name
     */
    #[Route('/register', name: 'register', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function register(Request $request): Response
    {
        $npub = $this->getUser()?->getUserIdentifier();
        $name = $request->request->get('vanity_name', '');
        $paymentTypeValue = $request->request->get('payment_type', 'subscription');

        // Validate payment type
        $paymentType = VanityNamePaymentType::tryFrom($paymentTypeValue);
        if ($paymentType === null || $paymentType === VanityNamePaymentType::ADMIN_GRANTED) {
            $this->addFlash('error', 'Invalid payment type selected.');
            return $this->redirectToRoute('vanity_index');
        }

        // Check if already has active vanity name
        if ($this->vanityNameService->hasActiveVanityName($npub)) {
            $this->addFlash('info', 'You already have an active vanity name.');
            return $this->redirectToRoute('vanity_settings');
        }

        try {
            $invoiceData = $this->vanityNameService->reserveWithInvoice($npub, $name, $paymentType);
            $qrSvg = $this->qrGenerator->svg('lightning:' . strtoupper($invoiceData['bolt11']), 280);

            return $this->render('subscription/vanity/invoice.html.twig', [
                'vanityName' => $invoiceData['vanityName'],
                'bolt11' => $invoiceData['bolt11'],
                'amount' => $invoiceData['amount'],
                'qrSvg' => $qrSvg,
                'serverDomain' => $this->vanityNameService->getServerDomain(),
                'isRenewal' => false,
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('vanity_index');
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('vanity_index');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Unexpected error creating invoice. Please check system configuration.');
            $this->logger->error('Vanity name invoice creation failed: ' . $e->getMessage());
            return $this->redirectToRoute('vanity_index');
        }
    }

    /**
     * Invoice page for pending vanity name (for viewing existing pending invoice)
     */
    #[Route('/invoice/{id}', name: 'invoice')]
    #[IsGranted('ROLE_USER')]
    public function invoice(int $id): Response
    {
        $npub = $this->getUser()?->getUserIdentifier();

        // Fetch by ID directly
        $vanityName = $this->vanityNameRepository->find($id);

        if ($vanityName === null || $vanityName->getNpub() !== $npub) {
            $this->addFlash('error', 'Vanity name not found or access denied.');
            return $this->redirectToRoute('vanity_index');
        }

        // If no pending invoice, create one
        $bolt11 = $vanityName->getPendingInvoiceBolt11();
        if (empty($bolt11)) {
            try {
                $invoiceData = $this->vanityNameService->createInvoice($vanityName);
                $bolt11 = $invoiceData['bolt11'];
            } catch (\Exception $e) {
                $this->addFlash('error', 'Failed to create invoice: ' . $e->getMessage());
                return $this->redirectToRoute('vanity_index');
            }
        }

        $qrSvg = $this->qrGenerator->svg('lightning:' . strtoupper($bolt11), 280);

        return $this->render('subscription/vanity/invoice.html.twig', [
            'vanityName' => $vanityName,
            'bolt11' => $bolt11,
            'amount' => $vanityName->getPaymentType()->getPriceInSats(),
            'qrSvg' => $qrSvg,
            'serverDomain' => $this->vanityNameService->getServerDomain(),
            'isRenewal' => false,
        ]);
    }

    /**
     * Settings page for existing vanity name
     */
    #[Route('/settings', name: 'settings')]
    #[IsGranted('ROLE_USER')]
    public function settings(): Response
    {
        $npub = $this->getUser()?->getUserIdentifier();
        $vanityName = $this->vanityNameService->getByNpub($npub);

        if ($vanityName === null) {
            return $this->redirectToRoute('vanity_index');
        }

        return $this->render('subscription/vanity/settings.html.twig', [
            'vanityName' => $vanityName,
            'serverDomain' => $this->vanityNameService->getServerDomain(),
        ]);
    }

    /**
     * Release vanity name
     */
    #[Route('/release', name: 'release', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function release(Request $request): Response
    {
        $npub = $this->getUser()?->getUserIdentifier();
        $vanityName = $this->vanityNameService->getByNpub($npub);

        if ($vanityName === null) {
            $this->addFlash('error', 'No vanity name found.');
            return $this->redirectToRoute('vanity_index');
        }

        // CSRF token check
        if (!$this->isCsrfTokenValid('release_vanity', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('vanity_settings');
        }

        $this->vanityNameService->release($vanityName);
        $this->addFlash('success', 'Vanity name "' . $vanityName->getVanityName() . '" has been released.');

        return $this->redirectToRoute('vanity_index');
    }

    /**
     * Renew subscription vanity name
     */
    #[Route('/renew', name: 'renew', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function renew(): Response
    {
        $npub = $this->getUser()?->getUserIdentifier();
        $vanityName = $this->vanityNameService->getByNpub($npub);

        if ($vanityName === null) {
            $this->addFlash('error', 'No vanity name found.');
            return $this->redirectToRoute('vanity_index');
        }

        if ($vanityName->getPaymentType() !== VanityNamePaymentType::SUBSCRIPTION) {
            $this->addFlash('error', 'Only subscription vanity names can be renewed.');
            return $this->redirectToRoute('vanity_settings');
        }

        try {
            $invoiceData = $this->vanityNameService->createInvoice($vanityName);
            $qrSvg = $this->qrGenerator->svg('lightning:' . strtoupper($invoiceData['bolt11']), 280);

            return $this->render('subscription/vanity/invoice.html.twig', [
                'vanityName' => $invoiceData['vanityName'],
                'bolt11' => $invoiceData['bolt11'],
                'amount' => $invoiceData['amount'],
                'qrSvg' => $qrSvg,
                'serverDomain' => $this->vanityNameService->getServerDomain(),
                'isRenewal' => true,
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to create renewal invoice: ' . $e->getMessage());
            return $this->redirectToRoute('vanity_settings');
        }
    }

    /**
     * Check payment status (AJAX)
     */
    #[Route('/check-payment/{id}', name: 'check_payment', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function checkPayment(int $id): JsonResponse
    {
        $npub = $this->getUser()?->getUserIdentifier();
        $vanityName = $this->vanityNameRepository->find($id);

        if ($vanityName === null || $vanityName->getNpub() !== $npub) {
            return $this->json(['error' => 'Vanity name not found'], 404);
        }

        return $this->json([
            'status' => $vanityName->getStatus()->value,
            'isActive' => $vanityName->getStatus()->isActive(),
        ]);
    }

    /**
     * Cancel pending vanity name
     */
    #[Route('/cancel', name: 'cancel', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancel(): Response
    {
        $npub = $this->getUser()?->getUserIdentifier();

        try {
            $this->vanityNameService->cancelPending($npub);
            $this->addFlash('success', 'Pending vanity name cancelled. You can try again with a different name.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to cancel: ' . $e->getMessage());
        }

        return $this->redirectToRoute('vanity_index');
    }
}




