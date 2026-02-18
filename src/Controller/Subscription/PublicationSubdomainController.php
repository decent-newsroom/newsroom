<?php

declare(strict_types=1);

namespace App\Controller\Subscription;

use App\Service\PublicationSubdomainService;
use App\Service\QRGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/subscription/publication-subdomain', name: 'publication_subdomain_')]
class PublicationSubdomainController extends AbstractController
{
    public function __construct(
        private readonly PublicationSubdomainService $service,
        private readonly QRGenerator $qrGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(): Response
    {
        $npub = $this->getUser()?->getUserIdentifier();
        $existingSubscription = null;
        if ($npub) {
            $subscription = $this->service->getByNpub($npub);
            // Only show active/pending subscriptions, hide cancelled/expired
            if ($subscription !== null &&
                ($subscription->getStatus()->value === 'active' ||
                 $subscription->getStatus()->value === 'pending')) {
                $existingSubscription = $subscription;
            }
        }
        return $this->render('subscription/publication_subdomain/index.html.twig', [
            'baseDomain' => $this->service->getBaseDomain(),
            'priceInSats' => \App\Entity\PublicationSubdomainSubscription::PRICE_SATS,
            'durationDays' => \App\Entity\PublicationSubdomainSubscription::DURATION_DAYS,
            'existingSubscription' => $existingSubscription,
        ]);
    }

    #[Route('/subscribe', name: 'subscribe')]
    #[IsGranted('ROLE_USER')]
    public function subscribe(): Response
    {
        $npub = $this->getUser()?->getUserIdentifier();
        $existingSubscription = $this->service->getByNpub($npub);

        // Only redirect to settings if subscription is active or pending
        // Cancelled/expired users should be able to subscribe again
        if ($existingSubscription !== null &&
            ($existingSubscription->getStatus()->value === 'active' ||
             $existingSubscription->getStatus()->value === 'pending')) {
            return $this->redirectToRoute('publication_subdomain_settings');
        }

        return $this->render('subscription/publication_subdomain/subscribe.html.twig', [
            'baseDomain' => $this->service->getBaseDomain(),
            'priceInSats' => \App\Entity\PublicationSubdomainSubscription::PRICE_SATS,
        ]);
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function create(Request $request): Response
    {
        $npub = $this->getUser()?->getUserIdentifier();
        $subdomain = $request->request->get('subdomain', '');
        $magazineCoordinate = $request->request->get('magazine_coordinate', '');
        if (!$this->isCsrfTokenValid('publication_subdomain_create', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('publication_subdomain_subscribe');
        }
        try {
            $subscription = $this->service->createSubscription($npub, $subdomain, $magazineCoordinate);
            $invoiceData = $this->service->createInvoice($subscription);
            return $this->redirectToRoute('publication_subdomain_invoice');
        } catch (\Exception $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('publication_subdomain_subscribe');
        }
    }

    #[Route('/invoice', name: 'invoice')]
    #[IsGranted('ROLE_USER')]
    public function invoice(): Response
    {
        $npub = $this->getUser()?->getUserIdentifier();
        $subscription = $this->service->getByNpub($npub);
        if ($subscription === null) {
            $this->addFlash('error', 'No subscription found.');
            return $this->redirectToRoute('publication_subdomain_index');
        }
        if ($subscription->getStatus()->value === 'active') {
            return $this->redirectToRoute('publication_subdomain_settings');
        }
        $bolt11 = $subscription->getPendingInvoiceBolt11();
        if (empty($bolt11)) {
            try {
                $invoiceData = $this->service->createInvoice($subscription);
                $bolt11 = $invoiceData['bolt11'];
            } catch (\Exception $e) {
                $this->addFlash('error', 'Failed to create invoice: ' . $e->getMessage());
                return $this->redirectToRoute('publication_subdomain_index');
            }
        }
        $qrSvg = $this->qrGenerator->svg('lightning:' . strtoupper($bolt11), 280);
        return $this->render('subscription/publication_subdomain/invoice.html.twig', [
            'subscription' => $subscription,
            'bolt11' => $bolt11,
            'amount' => \App\Entity\PublicationSubdomainSubscription::PRICE_SATS,
            'qrSvg' => $qrSvg,
            'baseDomain' => $this->service->getBaseDomain(),
        ]);
    }

    #[Route('/settings', name: 'settings')]
    #[IsGranted('ROLE_USER')]
    public function settings(): Response
    {
        $npub = $this->getUser()?->getUserIdentifier();
        $subscription = $this->service->getByNpub($npub);

        // If no subscription or cancelled/expired, redirect to index to subscribe
        if ($subscription === null ||
            $subscription->getStatus()->value === 'cancelled' ||
            $subscription->getStatus()->value === 'expired') {
            return $this->redirectToRoute('publication_subdomain_index');
        }

        return $this->render('subscription/publication_subdomain/settings.html.twig', [
            'subscription' => $subscription,
            'baseDomain' => $this->service->getBaseDomain(),
        ]);
    }

    /**
     * Cancel pending subscription and release subdomain
     */
    #[Route('/cancel', name: 'cancel', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancel(Request $request): Response
    {
        $npub = $this->getUser()?->getUserIdentifier();
        $subscription = $this->service->getByNpub($npub);

        if ($subscription === null) {
            $this->addFlash('error', 'No subscription found.');
            return $this->redirectToRoute('publication_subdomain_index');
        }

        if ($subscription->getStatus()->value !== 'pending') {
            $this->addFlash('error', 'Only pending subscriptions can be cancelled.');
            return $this->redirectToRoute('publication_subdomain_settings');
        }

        if (!$this->isCsrfTokenValid('publication_subdomain_cancel', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('publication_subdomain_settings');
        }

        try {
            $this->service->cancelSubscription($subscription);
            $this->addFlash('success', 'Pending subscription cancelled. You can now subscribe with a different subdomain.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to cancel: ' . $e->getMessage());
        }

        return $this->redirectToRoute('publication_subdomain_index');
    }
}
