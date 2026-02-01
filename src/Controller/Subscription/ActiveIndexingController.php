<?php

declare(strict_types=1);

namespace App\Controller\Subscription;

use App\Entity\ActiveIndexingSubscription;
use App\Enum\ActiveIndexingTier;
use App\Service\ActiveIndexingService;
use App\Service\AuthorRelayService;
use App\Service\QRGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use swentel\nostr\Key\Key;

#[Route('/subscription/active-indexing')]
class ActiveIndexingController extends AbstractController
{
    public function __construct(
        private readonly ActiveIndexingService $activeIndexingService,
        private readonly AuthorRelayService $authorRelayService,
        private readonly QRGenerator $qrGenerator,
    ) {}

    #[Route('', name: 'active_indexing_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        $subscription = null;
        $nip65Relays = [];

        if ($user) {
            $npub = $user->getUserIdentifier();
            $subscription = $this->activeIndexingService->getSubscription($npub);

            // Get NIP-65 relays for display
            try {
                $key = new Key();
                $pubkeyHex = $key->convertToHex($npub);
                $relayData = $this->authorRelayService->getAuthorRelays($pubkeyHex);
                $nip65Relays = $relayData['all'] ?? [];
            } catch (\Exception $e) {
                // Ignore errors, just show empty relay list
            }
        }

        return $this->render('subscription/active_indexing/index.html.twig', [
            'subscription' => $subscription,
            'tiers' => ActiveIndexingTier::cases(),
            'nip65Relays' => $nip65Relays,
        ]);
    }

    #[Route('/subscribe/{tier}', name: 'active_indexing_subscribe', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function subscribe(Request $request, string $tier): Response
    {
        $tierEnum = ActiveIndexingTier::tryFrom($tier);
        if (!$tierEnum) {
            $this->addFlash('error', 'Invalid subscription tier.');
            return $this->redirectToRoute('active_indexing_index');
        }

        $user = $this->getUser();
        $npub = $user->getUserIdentifier();

        // Check if already has active subscription
        if ($this->activeIndexingService->hasActiveSubscription($npub)) {
            $this->addFlash('info', 'You already have an active subscription.');
            return $this->redirectToRoute('active_indexing_settings');
        }

        try {
            $invoiceData = $this->activeIndexingService->createSubscriptionInvoice($npub, $tierEnum);
            $qrSvg = $this->qrGenerator->svg('lightning:' . strtoupper($invoiceData['bolt11']), 280);

            return $this->render('subscription/active_indexing/invoice.html.twig', [
                'subscription' => $invoiceData['subscription'],
                'bolt11' => $invoiceData['bolt11'],
                'amount' => $invoiceData['amount'],
                'tier' => $tierEnum,
                'qrSvg' => $qrSvg,
                'isRenewal' => false,
            ]);
        } catch (\RuntimeException $e) {
            $this->addFlash('error', 'Failed to create invoice: ' . $e->getMessage());
            return $this->redirectToRoute('active_indexing_index');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Unexpected error creating invoice. Please check system configuration.');
            error_log('Active Indexing invoice creation failed: ' . $e->getMessage());
            return $this->redirectToRoute('active_indexing_index');
        }
    }

    #[Route('/settings', name: 'active_indexing_settings', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function settings(): Response
    {
        $user = $this->getUser();
        $npub = $user->getUserIdentifier();
        $subscription = $this->activeIndexingService->getSubscription($npub);

        if (!$subscription || !$subscription->isActive()) {
            $this->addFlash('info', 'You need an active subscription to access settings.');
            return $this->redirectToRoute('active_indexing_index');
        }

        // Get NIP-65 relays for display
        $nip65Relays = [];
        try {
            $key = new Key();
            $pubkeyHex = $key->convertToHex($npub);
            $relayData = $this->authorRelayService->getAuthorRelays($pubkeyHex);
            $nip65Relays = $relayData['all'] ?? [];
        } catch (\Exception $e) {
            // Ignore errors
        }

        return $this->render('subscription/active_indexing/settings.html.twig', [
            'subscription' => $subscription,
            'nip65Relays' => $nip65Relays,
        ]);
    }

    #[Route('/settings/relays', name: 'active_indexing_update_relays', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function updateRelays(Request $request): Response
    {
        $user = $this->getUser();
        $npub = $user->getUserIdentifier();
        $subscription = $this->activeIndexingService->getSubscription($npub);

        if (!$subscription || !$subscription->isActive()) {
            $this->addFlash('error', 'No active subscription found.');
            return $this->redirectToRoute('active_indexing_index');
        }

        $useNip65 = $request->request->getBoolean('use_nip65_relays', true);
        $customRelaysRaw = $request->request->get('custom_relays', '');

        // Parse custom relays (one per line)
        $customRelays = [];
        if (!$useNip65 && !empty($customRelaysRaw)) {
            $customRelays = array_filter(
                array_map('trim', explode("\n", $customRelaysRaw)),
                fn($r) => !empty($r)
            );
        }

        try {
            $this->activeIndexingService->updateRelayConfiguration(
                $subscription,
                $useNip65,
                $customRelays
            );
            $this->addFlash('success', 'Relay configuration updated successfully.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to update relay configuration: ' . $e->getMessage());
        }

        return $this->redirectToRoute('active_indexing_settings');
    }

    #[Route('/renew/{tier}', name: 'active_indexing_renew', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function renew(string $tier): Response
    {
        $tierEnum = ActiveIndexingTier::tryFrom($tier);
        if (!$tierEnum) {
            $this->addFlash('error', 'Invalid subscription tier.');
            return $this->redirectToRoute('active_indexing_settings');
        }

        $user = $this->getUser();
        $npub = $user->getUserIdentifier();
        $subscription = $this->activeIndexingService->getSubscription($npub);

        if (!$subscription) {
            return $this->redirectToRoute('active_indexing_subscribe', ['tier' => $tier]);
        }

        try {
            // Update tier if different
            if ($subscription->getTier() !== $tierEnum) {
                $subscription->setTier($tierEnum);
            }

            $invoiceData = $this->activeIndexingService->createSubscriptionInvoice($npub, $tierEnum);
            $qrSvg = $this->qrGenerator->svg('lightning:' . strtoupper($invoiceData['bolt11']), 280);

            return $this->render('subscription/active_indexing/invoice.html.twig', [
                'subscription' => $invoiceData['subscription'],
                'bolt11' => $invoiceData['bolt11'],
                'amount' => $invoiceData['amount'],
                'tier' => $tierEnum,
                'qrSvg' => $qrSvg,
                'isRenewal' => true,
            ]);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to create renewal invoice: ' . $e->getMessage());
            return $this->redirectToRoute('active_indexing_settings');
        }
    }

    #[Route('/check-payment/{id}', name: 'active_indexing_check_payment', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function checkPayment(int $id): Response
    {
        $user = $this->getUser();
        $npub = $user->getUserIdentifier();
        $subscription = $this->activeIndexingService->getSubscription($npub);

        if (!$subscription || $subscription->getId() !== $id) {
            return $this->json(['error' => 'Subscription not found'], 404);
        }

        return $this->json([
            'status' => $subscription->getStatus()->value,
            'isActive' => $subscription->isActive(),
        ]);
    }

    #[Route('/cancel', name: 'active_indexing_cancel', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancel(): Response
    {
        $user = $this->getUser();
        $npub = $user->getUserIdentifier();

        try {
            $this->activeIndexingService->cancelPendingSubscription($npub);
            $this->addFlash('success', 'Pending subscription cancelled. You can try again with a different plan.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to cancel subscription: ' . $e->getMessage());
        }

        return $this->redirectToRoute('active_indexing_index');
    }
}
