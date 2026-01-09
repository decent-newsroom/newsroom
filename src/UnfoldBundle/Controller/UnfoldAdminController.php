<?php

namespace App\UnfoldBundle\Controller;

use App\Entity\UnfoldSite;
use App\Repository\UnfoldSiteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin controller for managing UnfoldSite records (subdomain ↔ naddr mapping)
 */
#[Route('/admin/unfold')]
class UnfoldAdminController extends AbstractController
{
    public function __construct(
        private readonly UnfoldSiteRepository $unfoldSiteRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('', name: 'unfold_admin_index', methods: ['GET'])]
    public function index(): Response
    {
        $sites = $this->unfoldSiteRepository->findAll();

        return $this->render('@Unfold/admin/index.html.twig', [
            'sites' => $sites,
        ]);
    }

    #[Route('/new', name: 'unfold_admin_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $subdomain = trim($request->request->get('subdomain', ''));
            $naddr = trim($request->request->get('naddr', ''));

            if (empty($subdomain) || empty($naddr)) {
                $this->addFlash('error', 'Subdomain and naddr are required.');
                return $this->redirectToRoute('unfold_admin_new');
            }

            // Check if subdomain already exists
            if ($this->unfoldSiteRepository->findBySubdomain($subdomain)) {
                $this->addFlash('error', 'Subdomain already exists.');
                return $this->redirectToRoute('unfold_admin_new');
            }

            $site = new UnfoldSite();
            $site->setSubdomain($subdomain);
            $site->setNaddr($naddr);

            $this->entityManager->persist($site);
            $this->entityManager->flush();

            $this->addFlash('success', 'Site created successfully.');
            return $this->redirectToRoute('unfold_admin_index');
        }

        return $this->render('@Unfold/admin/new.html.twig');
    }

    #[Route('/{id}/edit', name: 'unfold_admin_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $site = $this->unfoldSiteRepository->find($id);

        if (!$site) {
            throw $this->createNotFoundException('Site not found.');
        }

        if ($request->isMethod('POST')) {
            $subdomain = trim($request->request->get('subdomain', ''));
            $naddr = trim($request->request->get('naddr', ''));

            if (empty($subdomain) || empty($naddr)) {
                $this->addFlash('error', 'Subdomain and naddr are required.');
                return $this->redirectToRoute('unfold_admin_edit', ['id' => $id]);
            }

            // Check if subdomain already exists (excluding current site)
            $existing = $this->unfoldSiteRepository->findBySubdomain($subdomain);
            if ($existing && $existing->getId() !== $site->getId()) {
                $this->addFlash('error', 'Subdomain already exists.');
                return $this->redirectToRoute('unfold_admin_edit', ['id' => $id]);
            }

            $site->setSubdomain($subdomain);
            $site->setNaddr($naddr);

            $this->entityManager->flush();

            $this->addFlash('success', 'Site updated successfully.');
            return $this->redirectToRoute('unfold_admin_index');
        }

        return $this->render('@Unfold/admin/edit.html.twig', [
            'site' => $site,
        ]);
    }

    #[Route('/{id}/delete', name: 'unfold_admin_delete', methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $site = $this->unfoldSiteRepository->find($id);

        if (!$site) {
            throw $this->createNotFoundException('Site not found.');
        }

        // CSRF check
        if ($this->isCsrfTokenValid('delete' . $id, $request->request->get('_token'))) {
            $this->entityManager->remove($site);
            $this->entityManager->flush();
            $this->addFlash('success', 'Site deleted successfully.');
        }

        return $this->redirectToRoute('unfold_admin_index');
    }
}

