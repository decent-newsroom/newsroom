<?php

declare(strict_types=1);

namespace App\Controller\Administration\Chat;

use App\ChatBundle\Repository\ChatCommunityRepository;
use App\ChatBundle\Repository\ChatGroupRepository;
use App\ChatBundle\Service\ChatRelayClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/chat/communities/{communityId}/chats')]
#[IsGranted('ROLE_ADMIN')]
class ChatAdminChatViewController extends AbstractController
{
    public function __construct(
        private readonly ChatCommunityRepository $communityRepo,
        private readonly ChatGroupRepository $groupRepo,
        private readonly ChatRelayClient $relayClient,
    ) {}

    #[Route('/{groupSlug}', name: 'admin_chat_view')]
    public function show(int $communityId, string $groupSlug): Response
    {
        $community = $this->communityRepo->find($communityId) ?? throw $this->createNotFoundException();
        $group = $this->groupRepo->findBySlugAndCommunity($groupSlug, $community);
        if (!$group) {
            throw $this->createNotFoundException();
        }

        // Admin view: fetch all messages without moderation filtering
        $messages = $this->relayClient->fetchAllMessages(
            $group->getChannelEventId(),
            $community,
            100,
        );

        return $this->render('admin/chat/chats/show.html.twig', [
            'community' => $community,
            'group' => $group,
            'messages' => $messages,
        ]);
    }
}

