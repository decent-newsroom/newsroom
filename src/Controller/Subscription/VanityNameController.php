<?php

declare(strict_types=1);

namespace App\Controller\Subscription;

use App\Enum\VanityNamePaymentType;
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
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Vanity name registration page
     */
    #[Route('', name: 'index')]
    public function index(): Response
    {
        $npub = $this->getUser()?->getUserIdentifier();
        if ($npub !== null) {
            $existingVanity = $this->vanityNameService->getByNpub($npub);
        }

        return $this->render('subscription/vanity/index.html.twig', [
            'existingVanity' => $existingVanity ?? null,
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

        $npub = $this->getUser()?->getUserIdentifier();
        $error = $this->vanityNameService->getValidationError($name, $npub);

        return $this->json([
            'available' => $error === null,
            'error' => $error,
            'name' => strtolower($name),
        ]);
    }

    /**
     * Register a vanity name (free, activated immediately)
     */
    #[Route('/register', name: 'register', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function register(Request $request): Response
    {
        $npub = $this->getUser()?->getUserIdentifier();
        $name = $request->request->get('vanity_name', '');

        if ($this->vanityNameService->hasActiveVanityName($npub)) {
            $this->addFlash('info', 'You already have an active vanity name.');
            return $this->redirectToRoute('vanity_settings');
        }

        try {
            $this->vanityNameService->reserve($npub, $name, VanityNamePaymentType::FREE);
            $this->addFlash('success', 'Your vanity name "' . strtolower($name) . '@' . $this->vanityNameService->getServerDomain() . '" is now active!');
            return $this->redirectToRoute('vanity_settings');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('vanity_index');
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('vanity_index');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Unexpected error. Please try again.');
            $this->logger->error('Vanity name registration failed: ' . $e->getMessage());
            return $this->redirectToRoute('vanity_index');
        }
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
     * Cancel pending vanity name
     */
    #[Route('/cancel', name: 'cancel', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancel(): Response
    {
        $npub = $this->getUser()?->getUserIdentifier();

        try {
            $this->vanityNameService->cancelPending($npub);
            $this->addFlash('success', 'Pending vanity name cancelled. You can register a new one now.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Failed to cancel: ' . $e->getMessage());
        }

        return $this->redirectToRoute('vanity_index');
    }
}

