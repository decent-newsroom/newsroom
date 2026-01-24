<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Entity\UnfoldSite;
use App\Enum\KindsEnum;
use App\Repository\UnfoldSiteRepository;
use App\Service\Nostr\NostrClient;
use Doctrine\ORM\EntityManagerInterface;
use nostriphant\NIP19\Bech32;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin controller for managing Unfold Sites (subdomain ↔ AppData naddr mapping)
 *
 * This allows admins to:
 * 1. Create AppData events (kind 30078) that link magazines to themes
 * 2. Map subdomains to AppData naddrs for hosted magazine rendering
 */
#[Route('/admin/unfold')]
#[IsGranted('ROLE_ADMIN')]
class UnfoldSiteController extends AbstractController
{
    public function __construct(
        private readonly UnfoldSiteRepository $unfoldSiteRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly NostrClient $nostrClient,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * List all Unfold sites
     */
    #[Route('', name: 'admin_unfold_index', methods: ['GET'])]
    public function index(): Response
    {
        $sites = $this->unfoldSiteRepository->findAll();

        return $this->render('admin/unfold/index.html.twig', [
            'sites' => $sites,
        ]);
    }

    /**
     * Create a new Unfold site with AppData event
     */
    #[Route('/new', name: 'admin_unfold_new', methods: ['GET'])]
    public function new(): Response
    {
        // Available themes
        $themes = $this->getAvailableThemes();

        return $this->render('admin/unfold/new.html.twig', [
            'themes' => $themes,
        ]);
    }

    /**
     * API endpoint to publish signed AppData event and create UnfoldSite
     */
    #[Route('/publish', name: 'admin_unfold_publish', methods: ['POST'])]
    public function publish(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!isset($data['event'])) {
                return new JsonResponse(['error' => 'Missing signed event'], 400);
            }

            $signedEvent = $data['event'];
            $subdomain = $data['subdomain'] ?? null;

            // Validate the signed event
            if (!isset($signedEvent['id'], $signedEvent['sig'], $signedEvent['pubkey'], $signedEvent['kind'])) {
                return new JsonResponse(['error' => 'Invalid signed event structure'], 400);
            }

            // Verify it's a kind 30078 event
            if ($signedEvent['kind'] !== KindsEnum::APP_DATA->value) {
                return new JsonResponse([
                    'error' => sprintf('Event must be kind %d (AppData), got %d', KindsEnum::APP_DATA->value, $signedEvent['kind'])
                ], 400);
            }

            // Extract d-tag
            $dTag = $this->extractTag($signedEvent['tags'] ?? [], 'd');
            if (empty($dTag)) {
                return new JsonResponse(['error' => 'AppData event must have a d-tag'], 400);
            }

            // Extract magazine naddr from 'a' tag
            $magazineCoordinate = $this->extractTag($signedEvent['tags'] ?? [], 'a');
            if (empty($magazineCoordinate)) {
                return new JsonResponse(['error' => 'AppData event must have an "a" tag with magazine coordinate'], 400);
            }

            // Use subdomain from request or d-tag as fallback
            $subdomain = $this->sanitizeSubdomain($subdomain ?: $dTag);

            if (empty($subdomain)) {
                return new JsonResponse(['error' => 'Subdomain is required'], 400);
            }

            // Check if subdomain already exists
            if ($this->unfoldSiteRepository->findBySubdomain($subdomain)) {
                return new JsonResponse(['error' => 'Subdomain "' . $subdomain . '" already exists'], 400);
            }

            // Create swentel Event object and publish
            $event = new \swentel\nostr\Event\Event();
            $event->setId($signedEvent['id']);
            $event->setPublicKey($signedEvent['pubkey']);
            $event->setCreatedAt($signedEvent['created_at']);
            $event->setKind($signedEvent['kind']);
            $event->setTags($signedEvent['tags']);
            $event->setContent($signedEvent['content'] ?? '');
            $event->setSignature($signedEvent['sig']);

            // Publish to relays
            $relayResults = $this->nostrClient->publishEvent($event, []);

            $this->logger->info('Published AppData event', [
                'event_id' => $signedEvent['id'],
                'subdomain' => $subdomain,
                'results' => $relayResults,
            ]);

            // Build naddr for the AppData event
            $appDataNaddr = $this->buildNaddr(
                KindsEnum::APP_DATA->value,
                $signedEvent['pubkey'],
                $dTag
            );

            // Create the UnfoldSite record
            $site = new UnfoldSite();
            $site->setSubdomain($subdomain);
            $site->setNaddr($appDataNaddr);

            $this->entityManager->persist($site);
            $this->entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Unfold site created successfully',
                'subdomain' => $subdomain,
                'naddr' => $appDataNaddr,
                'siteId' => $site->getId(),
                'relayResults' => $this->formatRelayResults($relayResults),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to publish AppData event', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'error' => 'Failed to publish: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Edit an existing Unfold site
     */
    #[Route('/{id}/edit', name: 'admin_unfold_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $site = $this->unfoldSiteRepository->find($id);

        if (!$site) {
            throw $this->createNotFoundException('Site not found.');
        }

        if ($request->isMethod('POST')) {
            $subdomain = $this->sanitizeSubdomain($request->request->get('subdomain', ''));
            $naddr = trim($request->request->get('naddr', ''));

            if (empty($subdomain) || empty($naddr)) {
                $this->addFlash('error', 'Subdomain and naddr are required.');
                return $this->redirectToRoute('admin_unfold_edit', ['id' => $site->getId()]);
            }

            // Check if subdomain already exists (excluding current site)
            $existing = $this->unfoldSiteRepository->findBySubdomain($subdomain);
            if ($existing && $existing->getId() !== $site->getId()) {
                $this->addFlash('error', 'Subdomain "' . $subdomain . '" already exists.');
                return $this->redirectToRoute('admin_unfold_edit', ['id' => $site->getId()]);
            }

            $site->setSubdomain($subdomain);
            $site->setNaddr($naddr);

            $this->entityManager->flush();

            $this->addFlash('success', 'Unfold site updated successfully.');
            return $this->redirectToRoute('admin_unfold_index');
        }

        return $this->render('admin/unfold/edit.html.twig', [
            'site' => $site,
            'themes' => $this->getAvailableThemes(),
        ]);
    }

    /**
     * Delete an Unfold site
     */
    #[Route('/{id}/delete', name: 'admin_unfold_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $site = $this->unfoldSiteRepository->find($id);

        if (!$site) {
            throw $this->createNotFoundException('Site not found.');
        }

        if ($this->isCsrfTokenValid('delete' . $id, $request->request->get('_token'))) {
            $this->entityManager->remove($site);
            $this->entityManager->flush();
            $this->addFlash('success', 'Unfold site deleted successfully.');
        }

        return $this->redirectToRoute('admin_unfold_index');
    }

    /**
     * Build naddr from components
     */
    private function buildNaddr(int $kind, string $pubkey, string $identifier): string
    {
        return Bech32::naddr(
            kind: $kind,
            pubkey: $pubkey,
            identifier: $identifier,
            relays: []
        );
    }

    /**
     * Extract a tag value by name
     */
    private function extractTag(array $tags, string $name): ?string
    {
        foreach ($tags as $tag) {
            if (is_array($tag) && isset($tag[0], $tag[1]) && $tag[0] === $name) {
                return $tag[1];
            }
        }
        return null;
    }

    /**
     * Format relay results for JSON response
     */
    private function formatRelayResults(array $results): array
    {
        $formatted = [];
        foreach ($results as $relay => $result) {
            if (is_object($result)) {
                $formatted[] = [
                    'relay' => $relay,
                    'success' => $result->isSuccess ?? false,
                    'message' => $result->message ?? '',
                ];
            } elseif (is_array($result)) {
                $formatted[] = array_merge(['relay' => $relay], $result);
            } else {
                $formatted[] = [
                    'relay' => $relay,
                    'success' => (bool)$result,
                ];
            }
        }
        return $formatted;
    }

    /**
     * Get available themes
     */
    private function getAvailableThemes(): array
    {
        $themesPath = $this->getParameter('kernel.project_dir') . '/src/UnfoldBundle/Resources/themes';
        $themes = [];

        if (is_dir($themesPath)) {
            foreach (scandir($themesPath) as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                if (is_dir($themesPath . '/' . $item)) {
                    $themes[] = $item;
                }
            }
        }

        return $themes ?: ['casper'];
    }

    /**
     * Sanitize subdomain input
     */
    private function sanitizeSubdomain(string $subdomain): string
    {
        $subdomain = strtolower(trim($subdomain));
        $subdomain = preg_replace('/[^a-z0-9-]/', '', $subdomain);
        $subdomain = trim($subdomain, '-');

        return $subdomain;
    }
}

