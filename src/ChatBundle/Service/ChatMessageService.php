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
 *
 * Custodial users: server signs events via ChatEventSigner.
 * Self-sovereign users: client signs events; server validates and publishes.
 */
class ChatMessageService
{
    public function __construct(
        private readonly ChatEventSigner $signer,
        private readonly ChatRelayClient $relayClient,
        private readonly ChatAuthorizationChecker $authChecker,
        private readonly HubInterface $hub,
    ) {}

    /**
     * Send a message for a custodial user (server-side signing).
     */
    public function send(ChatUser $user, ChatGroup $group, string $content, ?string $replyToEventId = null): ChatMessageDto
    {
        if (!$user->isCustodial()) {
            throw new \RuntimeException('Custodial send() called for a self-sovereign user. Use publishSignedMessage() instead.');
        }

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

    /**
     * Publish a pre-signed kind-42 message from a self-sovereign user.
     * Validates the event structure, publishes to relay, pushes Mercure.
     *
     * @param string $signedEventJson Raw signed Nostr event JSON from the client
     */
    public function publishSignedMessage(ChatUser $user, ChatGroup $group, string $signedEventJson): ChatMessageDto
    {
        if (!$this->authChecker->canSendMessage($user, $group)) {
            throw new \RuntimeException('Not authorized to send messages in this group');
        }

        $event = json_decode($signedEventJson);
        if ($event === null) {
            throw new \RuntimeException('Invalid event JSON');
        }

        // Validate event structure
        $this->validateSignedEvent($event, $user, $group, 42);

        $community = $group->getCommunity();
        $result = $this->relayClient->publish($signedEventJson, $community);
        if (!$result['ok']) {
            throw new \RuntimeException('Failed to publish message: ' . ($result['message'] ?? 'unknown'));
        }

        $dto = new ChatMessageDto(
            eventId: $event->id ?? '',
            senderPubkey: $user->getPubkey(),
            senderDisplayName: $user->getDisplayName(),
            content: $event->content ?? '',
            createdAt: $event->created_at ?? time(),
            isReply: $this->hasReplyTag($event),
            replyToEventId: $this->getReplyEventId($event),
        );

        $topic = sprintf('/chat/%d/group/%s', $community->getId(), $group->getSlug());
        $this->hub->publish(new Update($topic, json_encode($dto)));

        $this->webPushService->dispatchPushNotification($group, $user);

        return $dto;
    }

    /**
     * Publish a pre-signed kind-43 (hide) or kind-44 (mute) moderation event.
     */
    public function publishSignedModeration(ChatUser $actor, ChatGroup $group, string $signedEventJson, int $expectedKind): void
    {
        $community = $group->getCommunity();
        if (!$this->authChecker->isGroupAdmin($actor, $group) && !$this->authChecker->isCommunityAdmin($actor, $community)) {
            throw new \RuntimeException('Not authorized for moderation actions');
        }

        $event = json_decode($signedEventJson);
        if ($event === null) {
            throw new \RuntimeException('Invalid event JSON');
        }

        $this->validateSignedEvent($event, $actor, $group, $expectedKind);

        $this->relayClient->publish($signedEventJson, $community);
    }

    public function hideMessage(ChatUser $actor, ChatGroup $group, string $eventId, string $reason = ''): void
    {
        if (!$actor->isCustodial()) {
            throw new \RuntimeException('Custodial hideMessage() called for a self-sovereign user. Use publishSignedModeration() instead.');
        }

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
        if (!$actor->isCustodial()) {
            throw new \RuntimeException('Custodial muteUser() called for a self-sovereign user. Use publishSignedModeration() instead.');
        }

        $community = $group->getCommunity();
        if (!$this->authChecker->isGroupAdmin($actor, $group) && !$this->authChecker->isCommunityAdmin($actor, $community)) {
            throw new \RuntimeException('Not authorized to mute users');
        }
        $relayUrl = $this->relayClient->getRelayUrl($community);
        $content = $reason !== '' ? json_encode(['reason' => $reason]) : '';
        $signedJson = $this->signer->signForUser($actor, 44, [['p', $targetPubkey, $relayUrl]], $content);
        $this->relayClient->publish($signedJson, $community);
    }

    /**
     * Validate that a pre-signed event matches the expected kind, pubkey, and channel.
     */
    private function validateSignedEvent(object $event, ChatUser $user, ChatGroup $group, int $expectedKind): void
    {
        if (!isset($event->kind) || (int) $event->kind !== $expectedKind) {
            throw new \RuntimeException(sprintf('Invalid event kind: expected %d', $expectedKind));
        }

        if (!isset($event->pubkey) || $event->pubkey !== $user->getPubkey()) {
            throw new \RuntimeException('Event pubkey does not match authenticated user');
        }

        if (!isset($event->sig) || $event->sig === '') {
            throw new \RuntimeException('Event is not signed');
        }

        if (!isset($event->id) || $event->id === '') {
            throw new \RuntimeException('Event has no id');
        }

        // For kind 42 messages, verify the root e-tag references the correct channel
        if ($expectedKind === 42) {
            $channelEventId = $group->getChannelEventId();
            $hasRootTag = false;
            foreach ($event->tags ?? [] as $tag) {
                if (($tag[0] ?? '') === 'e' && ($tag[1] ?? '') === $channelEventId && ($tag[3] ?? '') === 'root') {
                    $hasRootTag = true;
                    break;
                }
            }
            if (!$hasRootTag) {
                throw new \RuntimeException('Message event must reference the channel with an e/root tag');
            }
        }
    }

    private function hasReplyTag(object $event): bool
    {
        foreach ($event->tags ?? [] as $tag) {
            if (($tag[0] ?? '') === 'e' && ($tag[3] ?? '') === 'reply') {
                return true;
            }
        }
        return false;
    }

    private function getReplyEventId(object $event): ?string
    {
        foreach ($event->tags ?? [] as $tag) {
            if (($tag[0] ?? '') === 'e' && ($tag[3] ?? '') === 'reply') {
                return $tag[1] ?? null;
            }
        }
        return null;
    }
}

