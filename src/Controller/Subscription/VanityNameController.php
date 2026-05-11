<?php

declare(strict_types=1);

namespace App\Controller\Subscription;

use App\Enum\VanityNamePaymentType;
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
 * Controller for vanity name registration and management (paid Lightning flow).
 */
#[Route('/subscription/vanity', name: 'vanity_')]
class VanityNameController extends AbstractController
{
    public function __construct(
        private readonly VanityNameService $vanityNameService,
        private readonly QRGenerator $qrGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Vanity name landing page — shows pricing options (subscription / one-time).
     * Redirects immediately if the user already has an active or pending-payment name.
     */
    #[Route('', name: 'index')]
    public function index(): Response
    {
        $npub = $this->getUser()?->getUserIdentifier();
        $existingVanity = null;

        if ($npub !== null) {
            $existingVanity = $this->vanityNameService->getByNpub($npub);
        }

        if ($existingVanity !== null && $existingVanity->getStatus()->value === 'active') {
            return $this->redirectToRoute('vanity_settings');
        }

        if ($existingVanity !== null && $existingVanity->getStatus()->value === 'pending') {
            return $this->redirectToRoute('vanity_invoice');
        }

        return $this->render('subscription/vanity/index.html.twig', [
            'serverDomain' => $this->vanityNameService->getServerDomain(),
            'paymentTypes' => [
                VanityNamePaymentType::SUBSCRIPTION,
                VanityNamePaymentType::ONE_TIME,
            ],
        ]);
    }

    /**
     * Check availability of a vanity name (AJAX).
     */
    #[Route('/check', name: 'check', methods: ['GET'])]
    public function checkAvailability(Request $request): JsonResponse
    {
        $name = $request->query->get('name', '');

        if (empty($name)) {
            return $this->json(['available' => false, 'error' => 'Name is required']);
        }

        $npub = $this->getUser()?->getUserIdentifier();
        $error = $this->vanityNameService->getValidationError($name, $npub);

        return $this->json([
            'available' => $error === null,
            'error' => $error,
            'name' => strtolower($name),
        ]);
    }

    /**
     * Reserve a vanity name and create a Lightning invoice (paid flow).
     * Redirects to the invoice page after creating the pending reservation.
     */
    #[Route('/register', name: 'register', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function register(Request $request): Response
    {
        $npub = $this->getUser()?->getUserIdentifier();
        $name = $request->request->get('vanity_name', '');
        $paymentTypeValue = $request->request->get('payment_type', VanityNamePaymentType::SUBSCRIPTION->value);

        if ($this->vanityNameService->hasActiveVanityName($npub)) {
            $this->addFlash('info', 'You already have an active vanity name.');
            return $this->redirectToRoute('vanity_settings');
        }

        if ($this->vanityNameService->hasPendingVanityName($npub)) {
            $this->addFlash('info', 'You have a pending reservation. Complete the payment or cancel it first.');
            return $this->redirectToRoute('vanity_invoice');
        }

        $paymentType = VanityNamePaymentType::tryFrom($paymentTypeValue);
        if ($paymentType === null
            || $paymentType === VanityNamePaymentType::FREE
            || $paymentType === VanityNamePaymentType::ADMIN_GRANTED
        ) {
            $this->addFlash('error', 'Invalid payment type selected.');
            return $this->redirectToRoute('vanity_index');
        }

        try {
            $this->vanityNameService->reserveWithInvoice($npub, $name, $paymentType);
            $this->addFlash('success', 'Name "' . strtolower($name) . '" reserved! Scan the invoice below to activate it.');
            return $this->redirectToRoute('vanity_invoice');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Exception $e) {
            $this->addFlash('error', 'Unexpected error. Please try again.');
            $this->logger->error('Vanity name registration failed: ' . $e->getMessage());
        }

        return $this->redirectToRoute('vanity_index');
    }

    /**
     * Invoice page — shows QR code and polls for payment confirmation.
     */
    #[Route('/invoice', name: 'invoice')]
    #[IsGranted('ROLE_USER')]
    public function invoice(): Response
    {
        $npub = $this->getUser()?->getUserIdentifier();
        $vanityName = $this->vanityNameService->getByNpub($npub);

        if ($vanityName === null) {
            return $this->redirectToRoute('vanity_index');
        }

        if ($vanityName->getStatus()->value === 'active') {
            return $this->redirectToRoute('vanity_settings');
        }

        $bolt11 = $vanityName->getPendingInvoiceBolt11();

        // Regenerate if missing (e.g. LNURL was unavailable during initial reserve)
        if (empty($bolt11)) {
            try {
                $invoiceData = $this->vanityNameService->createInvoice($vanityName);
                $bolt11 = $invoiceData['bolt11'];
            } catch (\Exception $e) {
                $this->addFlash('error', 'Could not generate invoice: ' . $e->getMessage());
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
        ]);
    }

    /**
     * Poll payment status (AJAX) — used by the invoice page Stimulus controller.
     */
    #[Route('/check-payment', name: 'check_payment', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function checkPayment(): JsonResponse
    {
        $npub = $this->getUser()?->getUserIdentifier();
        $vanityName = $this->vanityNameService->getByNpub($npub);

        if ($vanityName === null) {
            return $this->json(['error' => 'No reservation found'], 404);
        }

        return $this->json([
            'status' => $vanityName->getStatus()->value,
            'isActive' => $vanityName->getStatus()->value === 'active',
        ]);
    }

    /**
     * Settings page for an active vanity name.
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
     * Release an active vanity name.
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

        if (!$this->isCsrfTokenValid('release_vanity', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('vanity_settings');
        }

        $this->vanityNameService->release($vanityName);
        $this->addFlash('success', 'Vanity name "' . $vanityName->getVanityName() . '" has been released.');

        return $this->redirectToRoute('vanity_index');
    }

    /**
     * Cancel a pending (unpaid) reservation.
     */
    #[Route('/cancel', name: 'cancel', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancel(): Response
    {
        $npub = $this->getUser()?->getUserIdentifier();

        try {
            $this->vanityNameService->cancelPending($npub);
            $this->addFlash('success', 'Reservation cancelled. You can register a new name now.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to cancel: ' . $e->getMessage());
        }

        return $this->redirectToRoute('vanity_index');
    }
}

