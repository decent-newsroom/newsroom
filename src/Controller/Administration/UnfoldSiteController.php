<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\UnfoldSiteRepository;
use App\Unfold\UnfoldSetupService;
use DecentNewsroom\UnfoldBundle\Theme\HandlebarsRenderer;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Operator management of local Unfold settings and hosting mappings. */
#[Route('/admin/unfold')]
#[IsGranted('ROLE_ADMIN')]
class UnfoldSiteController extends AbstractController
{
    public function __construct(
        private readonly UnfoldSiteRepository $unfoldSiteRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UnfoldSetupService $setup,
        private readonly HandlebarsRenderer $renderer,
        private readonly TranslatorInterface $translator,
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
     * Create a new Unfold site using local settings
     */
    #[Route('/new', name: 'admin_unfold_new', methods: ['GET'])]
    public function new(): Response
    {
        // Available themes
        $themes = $this->renderer->getAvailableThemes();

        // Get all published magazines with their permanent coordinates
        $magazines = $this->getMagazinesWithCoordinates();

        return $this->render('admin/unfold/new.html.twig', [
            'themes' => $themes,
            'magazines' => $magazines,
        ]);
    }

    #[Route('/new', name: 'admin_unfold_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('admin_unfold_create', $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('unfold_setup.invalid_csrf'));

            return $this->redirectToRoute('admin_unfold_new');
        }

        try {
            $this->setup->create(
                (string) $request->request->get('subdomain', ''),
                (string) $request->request->get('coordinate', ''),
                $request->request->has('theme') ? (string) $request->request->get('theme') : null,
            );
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $this->translator->trans($e->getMessage()));

            return $this->redirectToRoute('admin_unfold_new');
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save Unfold setup', ['exception' => $e]);
            $this->addFlash('error', $this->translator->trans('unfold_setup.save_failed'));

            return $this->redirectToRoute('admin_unfold_new');
        }

        $this->addFlash('success', $this->translator->trans('unfold_setup.created'));

        return $this->redirectToRoute('admin_unfold_index');
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
            if (!$this->isCsrfTokenValid('admin_unfold_edit' . $id, $request->request->get('_token'))) {
                $this->addFlash('error', $this->translator->trans('unfold_setup.invalid_csrf'));

                return $this->redirectToRoute('admin_unfold_edit', ['id' => $id]);
            }

            try {
                $this->setup->update(
                    $site,
                    (string) $request->request->get('subdomain', ''),
                    (string) $request->request->get('theme', 'default'),
                );
            } catch (\InvalidArgumentException $e) {
                $this->addFlash('error', $this->translator->trans($e->getMessage()));

                return $this->redirectToRoute('admin_unfold_edit', ['id' => $id]);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to update Unfold setup', ['exception' => $e]);
                $this->addFlash('error', $this->translator->trans('unfold_setup.save_failed'));

                return $this->redirectToRoute('admin_unfold_edit', ['id' => $id]);
            }

            $this->addFlash('success', $this->translator->trans('unfold_setup.updated'));

            return $this->redirectToRoute('admin_unfold_index');
        }

        return $this->render('admin/unfold/edit.html.twig', [
            'site' => $site,
            'themes' => $this->renderer->getAvailableThemes(),
            'selectedTheme' => $this->setup->getSettings($site->getCoordinate())->theme,
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

}
