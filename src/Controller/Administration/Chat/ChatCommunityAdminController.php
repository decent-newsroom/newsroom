<?php

declare(strict_types=1);

namespace App\Controller\Administration\Chat;

use App\ChatBundle\Entity\ChatCommunity;
use App\ChatBundle\Enum\ChatCommunityStatus;
use App\ChatBundle\Repository\ChatCommunityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/chat/communities')]
#[IsGranted('ROLE_ADMIN')]
class ChatCommunityAdminController extends AbstractController
{
    public function __construct(
        private readonly ChatCommunityRepository $communityRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'admin_chat_communities')]
    public function index(): Response
    {
        return $this->render('admin/chat/communities/index.html.twig', [
            'communities' => $this->communityRepo->findAll(),
        ]);
    }

    #[Route('/new', name: 'admin_chat_community_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $community = new ChatCommunity();
            $community->setSubdomain($request->request->get('subdomain', ''));
            $community->setName($request->request->get('name', ''));

            $relayUrl = $request->request->get('relay_url', '') ?: null;
            $community->setRelayUrl($relayUrl);

            $this->em->persist($community);
            $this->em->flush();

            $this->addFlash('success', 'Community created.');
            return $this->redirectToRoute('admin_chat_communities');
        }

        return $this->render('admin/chat/communities/form.html.twig', [
            'community' => null,
        ]);
    }

    #[Route('/{id}', name: 'admin_chat_community_show')]
    public function show(int $id): Response
    {
        $community = $this->communityRepo->find($id);
        if (!$community) {
            throw $this->createNotFoundException();
        }

        return $this->render('admin/chat/communities/show.html.twig', [
            'community' => $community,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_chat_community_edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $community = $this->communityRepo->find($id);
        if (!$community) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            $community->setName($request->request->get('name', $community->getName()));
            $relayUrl = $request->request->get('relay_url', '') ?: null;
            $community->setRelayUrl($relayUrl);

            $statusValue = $request->request->get('status', 'active');
            $community->setStatus(ChatCommunityStatus::from($statusValue));

            $this->em->flush();
            $this->addFlash('success', 'Community updated.');
            return $this->redirectToRoute('admin_chat_community_show', ['id' => $id]);
        }

        return $this->render('admin/chat/communities/form.html.twig', [
            'community' => $community,
        ]);
    }
}

