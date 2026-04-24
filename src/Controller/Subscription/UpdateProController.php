<?php

declare(strict_types=1);

namespace App\Controller\Subscription;

use App\Entity\User;
use App\Enum\UpdateProTier;
use App\Service\UpdateProService;
use App\Service\QRGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/subscription/updates-pro', name: 'updates_pro_')]
class UpdateProController extends AbstractController
{
    public function __construct(
        private readonly UpdateProService $proService,
        private readonly QRGenerator $qrGenerator,
    ) {
    }

    /**
     * Landing page — explains what Pro unlocks, shows current plan status.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        $subscription = null;

        if ($user instanceof User) {
            $subscription = $this->proService->getSubscription($user->getUserIdentifier());
        }

        return $this->render('subscription/updates_pro/index.html.twig', [
            'subscription' => $subscription,
            'tiers' => UpdateProTier::cases(),
            'freeCap' => UpdateProService::FREE_SUBSCRIPTION_CAP,
        ]);
    }

    /**
     * Generate BOLT11 invoice for the chosen tier.
     */
    #[Route('/subscribe/{tier}', name: 'subscribe', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function subscribe(string $tier): Response
    {
        $tierEnum = UpdateProTier::tryFrom($tier);
        if (!$tierEnum) {
            $this->addFlash('error', 'Invalid tier.');
            return $this->redirectToRoute('updates_pro_index');
        }

        /** @var User $user */
        $user = $this->getUser();
        $npub = $user->getUserIdentifier();

        if ($this->proService->hasActiveSubscription($npub)) {
            $this->addFlash('info', 'You already have an active Updates Pro subscription.');
            return $this->redirectToRoute('updates_pro_index');
        }

        try {
            $invoiceData = $this->proService->createSubscriptionInvoice($npub, $tierEnum);
            $qrSvg = $this->qrGenerator->svg('lightning:' . strtoupper($invoiceData['bolt11']), 280);

            return $this->render('subscription/updates_pro/invoice.html.twig', [
                'subscription' => $invoiceData['subscription'],
                'bolt11' => $invoiceData['bolt11'],
                'amount' => $invoiceData['amount'],
                'tier' => $tierEnum,
                'qrSvg' => $qrSvg,
                'isRenewal' => false,
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to create invoice: ' . $e->getMessage());
            return $this->redirectToRoute('updates_pro_index');
        }
    }

    /**
     * Renewal — creates a new invoice extending the current subscription.
     */
    #[Route('/renew/{tier}', name: 'renew', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function renew(string $tier): Response
    {
        $tierEnum = UpdateProTier::tryFrom($tier);
        if (!$tierEnum) {
            $this->addFlash('error', 'Invalid tier.');
            return $this->redirectToRoute('updates_pro_index');
        }

        /** @var User $user */
        $user = $this->getUser();
        $npub = $user->getUserIdentifier();

        try {
            $invoiceData = $this->proService->createSubscriptionInvoice($npub, $tierEnum);
            $qrSvg = $this->qrGenerator->svg('lightning:' . strtoupper($invoiceData['bolt11']), 280);

            return $this->render('subscription/updates_pro/invoice.html.twig', [
                'subscription' => $invoiceData['subscription'],
                'bolt11' => $invoiceData['bolt11'],
                'amount' => $invoiceData['amount'],
                'tier' => $tierEnum,
                'qrSvg' => $qrSvg,
                'isRenewal' => true,
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to create renewal invoice: ' . $e->getMessage());
            return $this->redirectToRoute('updates_pro_index');
        }
    }

    /**
     * JSON polling endpoint — the invoice page polls this until status = active.
     */
    #[Route('/check-payment/{id}', name: 'check_payment', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function checkPayment(int $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $sub = $this->proService->getSubscription($user->getUserIdentifier());

        if (!$sub || $sub->getId() !== $id) {
            return $this->json(['error' => 'Not found'], 404);
        }

        return $this->json([
            'status' => $sub->getStatus()->value,
            'isActive' => $sub->isActive(),
        ]);
    }

    /**
     * Cancel a PENDING invoice so the user can try again.
     */
    #[Route('/cancel', name: 'cancel', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancel(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        try {
            $this->proService->cancelPendingSubscription($user->getUserIdentifier());
            $this->addFlash('success', 'Pending subscription cancelled.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to cancel: ' . $e->getMessage());
        }

        return $this->redirectToRoute('updates_pro_index');
    }
}

