<?php

declare(strict_types=1);

namespace App\Controller\Administration\Chat;

use App\ChatBundle\Repository\ChatCommunityRepository;
use App\ChatBundle\Repository\ChatGroupRepository;
use App\ChatBundle\Repository\ChatGroupMembershipRepository;
use App\ChatBundle\Repository\ChatUserRepository;
use App\ChatBundle\Service\ChatGroupService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/chat/communities/{communityId}/groups')]
#[IsGranted('ROLE_ADMIN')]
class ChatGroupAdminController extends AbstractController
{
    public function __construct(
        private readonly ChatCommunityRepository $communityRepo,
        private readonly ChatGroupRepository $groupRepo,
        private readonly ChatGroupMembershipRepository $membershipRepo,
        private readonly ChatUserRepository $userRepo,
        private readonly ChatGroupService $groupService,
    ) {}

    #[Route('', name: 'admin_chat_groups')]
    public function index(int $communityId): Response
    {
        $community = $this->communityRepo->find($communityId) ?? throw $this->createNotFoundException();
        $groups = $this->groupRepo->findByCommunity($community);

        return $this->render('admin/chat/groups/index.html.twig', [
            'community' => $community,
            'groups' => $groups,
        ]);
    }

    #[Route('/create', name: 'admin_chat_group_create', methods: ['POST'])]
    public function create(int $communityId, Request $request): Response
    {
        $community = $this->communityRepo->find($communityId) ?? throw $this->createNotFoundException();

        $name = $request->request->get('name', '');
        $slug = $request->request->get('slug', '');

        // Find a community admin to sign the channel create event
        $users = $this->userRepo->findByCommunity($community);
        $creator = $users[0] ?? null;
        if ($creator === null) {
            $this->addFlash('error', 'Create at least one user before creating groups.');
            return $this->redirectToRoute('admin_chat_groups', ['communityId' => $communityId]);
        }

        $result = $this->groupService->createGroup($community, $name, $slug, $creator);

        // Check if result is an array (self-sovereign with unsigned event) or ChatGroup (custodial)
        if (is_array($result)) {
            // Self-sovereign user: return unsigned event for client-side signing
            $group = $result['group'];
            $unsignedEvent = $result['unsignedEvent'];

            return new JsonResponse([
                'groupId' => $group->getId(),
                'groupName' => $group->getName(),
                'unsignedEvent' => $unsignedEvent,
            ]);
        }

        // Custodial user: group is immediately created
        $this->addFlash('success', 'Group created.');
        return $this->redirectToRoute('admin_chat_groups', ['communityId' => $communityId]);
    }

    #[Route('/{groupId}/members', name: 'admin_chat_group_members')]
    public function members(int $communityId, int $groupId): Response
    {
        $community = $this->communityRepo->find($communityId) ?? throw $this->createNotFoundException();
        $group = $this->groupRepo->find($groupId) ?? throw $this->createNotFoundException();
        $members = $this->membershipRepo->findByGroup($group);

        return $this->render('admin/chat/groups/members.html.twig', [
            'community' => $community,
            'group' => $group,
            'members' => $members,
        ]);
    }

    #[Route('/{groupId}/archive', name: 'admin_chat_group_archive', methods: ['POST'])]
    public function archive(int $communityId, int $groupId): Response
    {
        $group = $this->groupRepo->find($groupId) ?? throw $this->createNotFoundException();
        $this->groupService->archiveGroup($group);

        $this->addFlash('success', 'Group archived.');
        return $this->redirectToRoute('admin_chat_groups', ['communityId' => $communityId]);
    }

    #[Route('/{groupId}/publish-channel-event', name: 'admin_chat_group_publish_channel', methods: ['POST'])]
    public function publishChannelEvent(int $communityId, int $groupId, Request $request): Response
    {
        $group = $this->groupRepo->find($groupId) ?? throw $this->createNotFoundException();

        $data = json_decode($request->getContent(), true);
        $signedEventJson = json_encode($data['signedEvent'] ?? null);

        if ($signedEventJson === 'null') {
            return new JsonResponse(['error' => 'Missing signedEvent'], 400);
        }

        $users = $this->userRepo->findByCommunity($group->getCommunity());
        $creator = $users[0] ?? null;

        if ($creator === null || $creator->getPubkey() !== ($data['signedEvent']['pubkey'] ?? '')) {
            return new JsonResponse(['error' => 'Unauthorized'], 403);
        }

        try {
            $this->groupService->publishSignedChannelEvent($group, $creator, $signedEventJson);
            return new JsonResponse(['success' => true, 'groupId' => $group->getId()]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }
}
