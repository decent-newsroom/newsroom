<?php

declare(strict_types=1);

namespace App\ChatBundle\Service;

use App\ChatBundle\Dto\ChatMessageDto;
use App\ChatBundle\Entity\ChatGroup;
use App\ChatBundle\Entity\ChatUser;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Orchestrates sending a chat message: sign → publish to relay → push Mercure.
 * No DB persistence — the relay is the sole store.
 */
class ChatMessageService
{
    public function __construct(
        private readonly ChatEventSigner $signer,
        private readonly ChatRelayClient $relayClient,
        private readonly ChatAuthorizationChecker $authChecker,
        private readonly HubInterface $hub,
    ) {}

    public function send(ChatUser $user, ChatGroup $group, string $content, ?string $replyToEventId = null): ChatMessageDto
    {
        if (!$this->authChecker->canSendMessage($user, $group)) {
            throw new \RuntimeException('Not authorized to send messages in this group');
        }

        $community = $group->getCommunity();
        $relayUrl = $this->relayClient->getRelayUrl($community);
        $channelEventId = $group->getChannelEventId();

        $tags = [['e', $channelEventId, $relayUrl, 'root']];
        if ($replyToEventId !== null) {
            $tags[] = ['e', $replyToEventId, $relayUrl, 'reply'];
        }

        $signedJson = $this->signer->signForUser($user, 42, $tags, $content);
        $signedEvent = json_decode($signedJson);

        $result = $this->relayClient->publish($signedJson, $community);
        if (!$result['ok']) {
            throw new \RuntimeException('Failed to publish message: ' . ($result['message'] ?? 'unknown'));
        }

        $dto = new ChatMessageDto(
            eventId: $signedEvent->id ?? '',
            senderPubkey: $user->getPubkey(),
            senderDisplayName: $user->getDisplayName(),
            content: $content,
            createdAt: $signedEvent->created_at ?? time(),
            isReply: $replyToEventId !== null,
            replyToEventId: $replyToEventId,
        );

        $topic = sprintf('/chat/%d/group/%s', $community->getId(), $group->getSlug());
        $this->hub->publish(new Update($topic, json_encode($dto)));

        return $dto;
    }

    public function hideMessage(ChatUser $actor, ChatGroup $group, string $eventId, string $reason = ''): void
    {
        $community = $group->getCommunity();
        if (!$this->authChecker->isGroupAdmin($actor, $group) && !$this->authChecker->isCommunityAdmin($actor, $community)) {
            throw new \RuntimeException('Not authorized to hide messages');
        }
        $content = $reason !== '' ? json_encode(['reason' => $reason]) : '';
        $signedJson = $this->signer->signForUser($actor, 43, [['e', $eventId]], $content);
        $this->relayClient->publish($signedJson, $community);
    }

    public function muteUser(ChatUser $actor, ChatGroup $group, string $targetPubkey, string $reason = ''): void
    {
        $community = $group->getCommunity();
        if (!$this->authChecker->isGroupAdmin($actor, $group) && !$this->authChecker->isCommunityAdmin($actor, $community)) {
            throw new \RuntimeException('Not authorized to mute users');
        }
        $relayUrl = $this->relayClient->getRelayUrl($community);
        $content = $reason !== '' ? json_encode(['reason' => $reason]) : '';
        $signedJson = $this->signer->signForUser($actor, 44, [['p', $targetPubkey, $relayUrl]], $content);
        $this->relayClient->publish($signedJson, $community);
    }
}

