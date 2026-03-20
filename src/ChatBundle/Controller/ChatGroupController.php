<?php

declare(strict_types=1);

namespace App\ChatBundle\Controller;

use App\ChatBundle\Entity\ChatUser;
use App\ChatBundle\Repository\ChatGroupMembershipRepository;
use App\ChatBundle\Repository\ChatGroupRepository;
use App\ChatBundle\Service\ChatAuthorizationChecker;
use App\ChatBundle\Service\ChatCommunityResolver;
use App\ChatBundle\Service\ChatMessageService;
use App\ChatBundle\Service\ChatRelayClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ChatGroupController extends AbstractController
{
    public function __construct(
        private readonly ChatCommunityResolver $communityResolver,
        private readonly ChatGroupRepository $groupRepo,
        private readonly ChatGroupMembershipRepository $membershipRepo,
        private readonly ChatAuthorizationChecker $authChecker,
        private readonly ChatRelayClient $relayClient,
        private readonly ChatMessageService $messageService,
    ) {}

    public function index(): Response
    {
        $community = $this->communityResolver->resolve();
        $user = $this->getChatUser();

        // Get groups the user belongs to
        $memberships = $this->membershipRepo->findByUser($user);
        $groups = array_map(fn($m) => $m->getGroup(), $memberships);
        $groups = array_filter($groups, fn($g) => $g->isActive());

        return $this->render('@Chat/groups/index.html.twig', [
            'community' => $community,
            'groups' => $groups,
        ]);
    }

    public function show(string $slug): Response
    {
        $community = $this->communityResolver->resolve();
        $user = $this->getChatUser();
        $group = $this->groupRepo->findBySlugAndCommunity($slug, $community);

        if ($group === null || !$this->authChecker->canAccessGroup($user, $group)) {
            throw $this->createNotFoundException();
        }

        $messages = $this->relayClient->fetchFilteredMessages(
            $group->getChannelEventId(),
            $community,
            50,
        );

        return $this->render('@Chat/groups/show.html.twig', [
            'community' => $community,
            'group' => $group,
            'messages' => $messages,
            'currentUserPubkey' => $user->getPubkey(),
            'isCustodial' => $user->isCustodial(),
            'relayUrl' => $this->relayClient->getRelayUrl($community),
        ]);
    }

    public function sendMessage(string $slug, Request $request): JsonResponse
    {
        $community = $this->communityResolver->resolve();
        $user = $this->getChatUser();
        $group = $this->groupRepo->findBySlugAndCommunity($slug, $community);

        if ($group === null || !$this->authChecker->canSendMessage($user, $group)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        if (!$user->isCustodial()) {
            return new JsonResponse(['error' => 'Self-sovereign users must use the /messages/signed endpoint'], 400);
        }

        $data = json_decode($request->getContent(), true);
        $content = trim($data['content'] ?? '');
        $replyTo = $data['replyTo'] ?? null;

        if ($content === '') {
            return new JsonResponse(['error' => 'Message cannot be empty'], 400);
        }

        try {
            $dto = $this->messageService->send($user, $group, $content, $replyTo);
            return new JsonResponse(['ok' => true, 'eventId' => $dto->eventId]);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Accept a pre-signed kind-42 event from a self-sovereign user.
     */
    public function sendSignedMessage(string $slug, Request $request): JsonResponse
    {
        $community = $this->communityResolver->resolve();
        $user = $this->getChatUser();
        $group = $this->groupRepo->findBySlugAndCommunity($slug, $community);

        if ($group === null || !$this->authChecker->canSendMessage($user, $group)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $signedEventJson = $request->getContent();
        if (empty($signedEventJson)) {
            return new JsonResponse(['error' => 'Empty request body'], 400);
        }

        try {
            $dto = $this->messageService->publishSignedMessage($user, $group, $signedEventJson);
            return new JsonResponse(['ok' => true, 'eventId' => $dto->eventId]);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Accept a pre-signed kind-43 (hide) event from a self-sovereign admin.
     */
    public function hideSignedMessage(string $slug, Request $request): JsonResponse
    {
        $community = $this->communityResolver->resolve();
        $user = $this->getChatUser();
        $group = $this->groupRepo->findBySlugAndCommunity($slug, $community);

        if ($group === null) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        try {
            $this->messageService->publishSignedModeration($user, $group, $request->getContent(), 43);
            return new JsonResponse(['ok' => true]);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Accept a pre-signed kind-44 (mute) event from a self-sovereign admin.
     */
    public function muteSignedUser(string $slug, Request $request): JsonResponse
    {
        $community = $this->communityResolver->resolve();
        $user = $this->getChatUser();
        $group = $this->groupRepo->findBySlugAndCommunity($slug, $community);

        if ($group === null) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        try {
            $this->messageService->publishSignedModeration($user, $group, $request->getContent(), 44);
            return new JsonResponse(['ok' => true]);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    public function history(string $slug, Request $request): JsonResponse
    {
        $community = $this->communityResolver->resolve();
        $user = $this->getChatUser();
        $group = $this->groupRepo->findBySlugAndCommunity($slug, $community);

        if ($group === null || !$this->authChecker->canAccessGroup($user, $group)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        $before = $request->query->getInt('before', 0) ?: null;

        $messages = $this->relayClient->fetchFilteredMessages(
            $group->getChannelEventId(),
            $community,
            50,
            $before,
        );

        return new JsonResponse($messages);
    }

    private function getChatUser(): ChatUser
    {
        $user = $this->getUser();
        if (!$user instanceof ChatUser) {
            throw $this->createAccessDeniedException();
        }
        return $user;
    }
}

