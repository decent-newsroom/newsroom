<?php

declare(strict_types=1);

namespace App\Controller\Administration;

use App\Entity\FollowPackSource;
use App\Enum\FollowPackPurpose;
use App\Repository\FollowPackSourceRepository;
use App\Service\FollowPackService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin controller for managing follow packs (kind 39089) and their assignment
 * to purposes (Podcasts, News Bots tabs on the home feed).
 */
#[Route('/admin/follow-packs', name: 'admin_follow_packs_')]
#[IsGranted('ROLE_ADMIN')]
class FollowPackAdminController extends AbstractController
{
    public function __construct(
        private readonly FollowPackService $followPackService,
        private readonly FollowPackSourceRepository $sourceRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * List all follow pack events and current source assignments.
     */
    #[Route('', name: 'index')]
    public function index(): Response
    {
        $followPacks = $this->followPackService->getAllFollowPackEvents();

        // Deduplicate by pubkey+d-tag (keep latest)
        $deduplicated = [];
        foreach ($followPacks as $pack) {
            $slug = $pack->getSlug();
            $key = $pack->getPubkey() . ':' . $slug;
            if (!isset($deduplicated[$key]) || $pack->getCreatedAt() > $deduplicated[$key]->getCreatedAt()) {
                $deduplicated[$key] = $pack;
            }
        }
        $followPacks = array_values($deduplicated);

        // Get current assignments
        $currentSources = [];
        foreach (FollowPackPurpose::cases() as $purpose) {
            $source = $this->sourceRepository->findByPurpose($purpose);
            $currentSources[$purpose->value] = $source;
        }

        return $this->render('admin/follow_packs/index.html.twig', [
            'followPacks' => $followPacks,
            'purposes' => FollowPackPurpose::cases(),
            'currentSources' => $currentSources,
        ]);
    }

    /**
     * Assign a follow pack coordinate to a purpose.
     */
    #[Route('/set-source', name: 'set_source', methods: ['POST'])]
    public function setSource(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('follow_pack_set_source', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('admin_follow_packs_index');
        }

        $purposeValue = $request->request->get('purpose');
        $coordinate = $request->request->get('coordinate', '');

        $purpose = FollowPackPurpose::tryFrom($purposeValue);
        if (!$purpose) {
            $this->addFlash('error', 'Invalid purpose.');
            return $this->redirectToRoute('admin_follow_packs_index');
        }

        $coordinate = trim($coordinate);
        if (empty($coordinate)) {
            // Remove existing source
            $existing = $this->sourceRepository->findByPurpose($purpose);
            if ($existing) {
                $this->em->remove($existing);
                $this->em->flush();
                $this->addFlash('success', sprintf('Removed %s source assignment.', $purpose->label()));
            }
            return $this->redirectToRoute('admin_follow_packs_index');
        }

        // Validate coordinate format: kind:pubkey:d-tag
        $parts = explode(':', $coordinate, 3);
        if (count($parts) < 3) {
            $this->addFlash('error', 'Invalid coordinate format. Expected: kind:pubkey:d-tag');
            return $this->redirectToRoute('admin_follow_packs_index');
        }

        // Create or update the source
        $source = $this->sourceRepository->findByPurpose($purpose);
        if (!$source) {
            $source = new FollowPackSource();
            $source->setPurpose($purpose);
        }
        $source->setCoordinate($coordinate);

        $this->em->persist($source);
        $this->em->flush();

        $this->addFlash('success', sprintf('Set %s source to: %s', $purpose->label(), $coordinate));
        return $this->redirectToRoute('admin_follow_packs_index');
    }

    /**
     * Remove a source assignment.
     */
    #[Route('/remove-source/{purpose}', name: 'remove_source', methods: ['POST'])]
    public function removeSource(string $purpose, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('follow_pack_remove_source', $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid security token.');
            return $this->redirectToRoute('admin_follow_packs_index');
        }

        $purposeEnum = FollowPackPurpose::tryFrom($purpose);
        if (!$purposeEnum) {
            $this->addFlash('error', 'Invalid purpose.');
            return $this->redirectToRoute('admin_follow_packs_index');
        }

        $source = $this->sourceRepository->findByPurpose($purposeEnum);
        if ($source) {
            $this->em->remove($source);
            $this->em->flush();
            $this->addFlash('success', sprintf('Removed %s source assignment.', $purposeEnum->label()));
        }

        return $this->redirectToRoute('admin_follow_packs_index');
    }
}




