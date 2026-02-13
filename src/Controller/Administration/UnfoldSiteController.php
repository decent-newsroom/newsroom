<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Entity\Event;
use App\Entity\UnfoldSite;
use App\Enum\KindsEnum;
use App\Repository\UnfoldSiteRepository;
use App\Service\Nostr\NostrClient;
use Doctrine\ORM\EntityManagerInterface;
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

        // Get all published magazines with their naddrs
        $magazines = $this->getMagazinesWithCoordinates();

        return $this->render('admin/unfold/new.html.twig', [
            'themes' => $themes,
            'magazines' => $magazines,
        ]);
    }

    /**
     * Get all magazines with their coordinates built
     * Queries the Event table directly for kind 30040 (magazine index) events
     */
    private function getMagazinesWithCoordinates(): array
    {
        // Query Events directly for kind 30040 (PUBLICATION_INDEX)
        $eventRepository = $this->entityManager->getRepository(Event::class);
        $magazineEvents = $eventRepository->findBy(
            ['kind' => KindsEnum::PUBLICATION_INDEX->value],
            ['created_at' => 'DESC']
        );

        $this->logger->debug('Found magazine events for Unfold dropdown', [
            'count' => count($magazineEvents),
        ]);

        $result = [];
        $seenSlugs = []; // Track unique slugs to avoid duplicates

        foreach ($magazineEvents as $event) {
            $pubkey = $event->getPubkey();
            $tags = $event->getTags() ?? [];

            // Extract d-tag (slug/identifier)
            $slug = null;
            $title = null;
            $image = null;

            foreach ($tags as $tag) {
                if (!is_array($tag) || count($tag) < 2) {
                    continue;
                }

                match ($tag[0]) {
                    'd' => $slug = $tag[1],
                    'title', 'name' => $title = $title ?? $tag[1],
                    'image', 'thumb' => $image = $image ?? $tag[1],
                    default => null,
                };
            }

            // Skip if no slug or pubkey
            if (empty($slug) || empty($pubkey)) {
                continue;
            }

            // Skip duplicates (keep the newest one, which comes first due to ORDER BY)
            $uniqueKey = $pubkey . ':' . $slug;
            if (isset($seenSlugs[$uniqueKey])) {
                continue;
            }
            $seenSlugs[$uniqueKey] = true;

            // Coordinate format: kind:pubkey:identifier
            $coordinate = sprintf('%d:%s:%s', KindsEnum::PUBLICATION_INDEX->value, $pubkey, $slug);

            $result[] = [
                'id' => $event->getId(),
                'title' => $title ?: $slug,
                'slug' => $slug,
                'coordinate' => $coordinate,
                'image' => $image,
            ];
        }

        $this->logger->debug('Magazines with coordinates built', [
            'totalEvents' => count($magazineEvents),
            'uniqueMagazines' => count($result),
        ]);

        return $result;
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

            // Extract magazine coordinate from 'a' tag
            $magazineCoordinate = $this->extractTag($signedEvent['tags'] ?? [], 'a');
            if (empty($magazineCoordinate)) {
                return new JsonResponse(['error' => 'AppData event must have an "a" tag with magazine coordinate'], 400);
            }

            // Validate coordinate format (kind:pubkey:identifier)
            $coordParts = explode(':', $magazineCoordinate, 3);
            if (count($coordParts) !== 3) {
                return new JsonResponse(['error' => 'Invalid magazine coordinate format in "a" tag'], 400);
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
                'coordinate' => $magazineCoordinate,
                'results' => $relayResults,
            ]);

            // Create the UnfoldSite record - store the coordinate directly
            $site = new UnfoldSite();
            $site->setSubdomain($subdomain);
            $site->setCoordinate($magazineCoordinate);

            $this->entityManager->persist($site);
            $this->entityManager->flush();

            return new JsonResponse([
                'success' => true,
                'message' => 'Unfold site created successfully',
                'subdomain' => $subdomain,
                'coordinate' => $magazineCoordinate,
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
            $coordinate = trim($request->request->get('coordinate', ''));

            if (empty($subdomain) || empty($coordinate)) {
                $this->addFlash('error', 'Subdomain and magazine coordinate are required.');
                return $this->redirectToRoute('admin_unfold_edit', ['id' => $site->getId()]);
            }

            // Validate coordinate format
            $coordParts = explode(':', $coordinate, 3);
            if (count($coordParts) !== 3) {
                $this->addFlash('error', 'Invalid coordinate format. Expected: kind:pubkey:identifier');
                return $this->redirectToRoute('admin_unfold_edit', ['id' => $site->getId()]);
            }

            // Check if subdomain already exists (excluding current site)
            $existing = $this->unfoldSiteRepository->findBySubdomain($subdomain);
            if ($existing && $existing->getId() !== $site->getId()) {
                $this->addFlash('error', 'Subdomain "' . $subdomain . '" already exists.');
                return $this->redirectToRoute('admin_unfold_edit', ['id' => $site->getId()]);
            }

            $site->setSubdomain($subdomain);
            $site->setCoordinate($coordinate);

            $this->entityManager->flush();

            $this->addFlash('success', 'Unfold site updated successfully.');
            return $this->redirectToRoute('admin_unfold_index');
        }

        return $this->render('admin/unfold/edit.html.twig', [
            'site' => $site,
            'themes' => $this->getAvailableThemes(),
            'magazines' => $this->getMagazinesWithCoordinates(),
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
     * Preview an Unfold site configuration
     */
    #[Route('/{id}/preview', name: 'admin_unfold_preview', methods: ['GET'])]
    public function preview(int $id): Response
    {
        $site = $this->unfoldSiteRepository->find($id);

        if (!$site) {
            throw $this->createNotFoundException('Site not found.');
        }

        return $this->render('admin/unfold/preview.html.twig', [
            'site' => $site,
        ]);
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

