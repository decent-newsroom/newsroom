<?php

declare(strict_types=1);

namespace App\ChatBundle\Service;

use App\ChatBundle\Dto\ChatMessageDto;
use App\ChatBundle\Entity\ChatCommunity;
use App\ChatBundle\Repository\ChatUserRepository;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\EventMessage;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Relay\Relay;
use swentel\nostr\Subscription\Subscription;

/**
 * Thin relay client scoped to the private chat relay.
 * All chat relay I/O (publish, fetch, filter) goes through this service.
 *
 * Messages are stored only on the relay — no database persistence.
 */
class ChatRelayClient
{
    private string $chatRelayUrl;

    public function __construct(
        string $chatRelayUrl,
        private readonly ChatUserRepository $userRepository,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->chatRelayUrl = $chatRelayUrl;
    }

    /**
     * Get the effective relay URL, allowing per-community overrides.
     */
    public function getRelayUrl(?ChatCommunity $community = null): string
    {
        if ($community !== null && $community->getRelayUrl() !== null) {
            return $community->getRelayUrl();
        }
        return $this->chatRelayUrl;
    }

    /**
     * Publish a signed event JSON string to the chat relay.
     *
     * @return array{ok: bool, message: string}
     */
    public function publish(string $signedEventJson, ?ChatCommunity $community = null): array
    {
        $relayUrl = $this->getRelayUrl($community);

        try {
            $relay = new Relay($relayUrl);
            $client = $relay->getClient();
            if (method_exists($client, 'setTimeout')) {
                $client->setTimeout(10);
            }

            $client->text($signedEventJson);
            $response = $client->receive();
            $client->disconnect();

            if ($response === null) {
                return ['ok' => false, 'message' => 'Null response from relay'];
            }

            $decoded = json_decode($response->getContent(), true);
            $ok = ($decoded[0] ?? '') === 'OK' && ($decoded[2] ?? false) === true;

            return ['ok' => $ok, 'message' => $decoded[3] ?? ''];
        } catch (\Throwable $e) {
            $this->logger?->error('ChatRelayClient: publish failed', [
                'relay' => $relayUrl,
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Fetch kind 42 (NIP-28) messages for a channel, with optional pagination.
     *
     * @return array Raw event objects from the relay
     */
    public function fetchMessages(
        string $channelEventId,
        int $limit = 50,
        ?int $until = null,
        ?ChatCommunity $community = null,
    ): array {
        $filter = [
            'kinds' => [42],
            '#e' => [$channelEventId],
            'limit' => $limit,
        ];

        if ($until !== null) {
            $filter['until'] = $until;
        }

        return $this->query($filter, $community);
    }

    /**
     * Fetch kind 43 (hide) and kind 44 (mute) moderation events for a channel.
     *
     * @return array{hidden: string[], muted: string[]} Event IDs hidden and pubkeys muted
     */
    public function fetchModeration(string $channelEventId, ?ChatCommunity $community = null): array
    {
        $events = $this->query([
            'kinds' => [43, 44],
            '#e' => [$channelEventId],
        ], $community);

        $hidden = [];
        $muted = [];

        foreach ($events as $event) {
            $kind = $event->kind ?? null;
            $tags = $event->tags ?? [];

            if ($kind === 43) {
                // Hide message: e tag references the hidden message
                foreach ($tags as $tag) {
                    if (($tag[0] ?? '') === 'e' && isset($tag[1])) {
                        $hidden[] = $tag[1];
                    }
                }
            } elseif ($kind === 44) {
                // Mute user: p tag references the muted pubkey
                foreach ($tags as $tag) {
                    if (($tag[0] ?? '') === 'p' && isset($tag[1])) {
                        $muted[] = $tag[1];
                    }
                }
            }
        }

        return ['hidden' => array_unique($hidden), 'muted' => array_unique($muted)];
    }

    /**
     * Fetch messages and apply moderation filtering.
     * Returns ChatMessageDto[] with hidden/muted messages removed.
     *
     * @return ChatMessageDto[]
     */
    public function fetchFilteredMessages(
        string $channelEventId,
        ChatCommunity $community,
        int $limit = 50,
        ?int $until = null,
    ): array {
        $rawEvents = $this->fetchMessages($channelEventId, $limit, $until, $community);
        $moderation = $this->fetchModeration($channelEventId, $community);

        // Resolve display names for all sender pubkeys
        $pubkeys = array_unique(array_map(fn($e) => $e->pubkey ?? '', $rawEvents));
        $users = $this->userRepository->findByPubkeys($pubkeys, $community);
        $nameMap = [];
        foreach ($users as $user) {
            $nameMap[$user->getPubkey()] = $user->getDisplayName();
        }

        $messages = [];
        foreach ($rawEvents as $event) {
            $eventId = $event->id ?? '';
            $senderPubkey = $event->pubkey ?? '';

            // Skip hidden messages and muted users
            if (in_array($eventId, $moderation['hidden'], true)) {
                continue;
            }
            if (in_array($senderPubkey, $moderation['muted'], true)) {
                continue;
            }

            // Check for reply markers
            $isReply = false;
            $replyToEventId = null;
            foreach ($event->tags ?? [] as $tag) {
                if (($tag[0] ?? '') === 'e' && isset($tag[3]) && $tag[3] === 'reply') {
                    $isReply = true;
                    $replyToEventId = $tag[1] ?? null;
                    break;
                }
            }

            $messages[] = new ChatMessageDto(
                eventId: $eventId,
                senderPubkey: $senderPubkey,
                senderDisplayName: $nameMap[$senderPubkey] ?? substr($senderPubkey, 0, 12) . '…',
                content: $event->content ?? '',
                createdAt: $event->created_at ?? 0,
                isReply: $isReply,
                replyToEventId: $replyToEventId,
            );
        }

        // Sort by created_at ascending (oldest first for display)
        usort($messages, fn(ChatMessageDto $a, ChatMessageDto $b) => $a->createdAt <=> $b->createdAt);

        return $messages;
    }

    /**
     * Fetch messages without moderation filtering (for admin views).
     *
     * @return ChatMessageDto[]
     */
    public function fetchAllMessages(
        string $channelEventId,
        ChatCommunity $community,
        int $limit = 50,
        ?int $until = null,
    ): array {
        $rawEvents = $this->fetchMessages($channelEventId, $limit, $until, $community);
        $moderation = $this->fetchModeration($channelEventId, $community);

        $pubkeys = array_unique(array_map(fn($e) => $e->pubkey ?? '', $rawEvents));
        $users = $this->userRepository->findByPubkeys($pubkeys, $community);
        $nameMap = [];
        foreach ($users as $user) {
            $nameMap[$user->getPubkey()] = $user->getDisplayName();
        }

        $messages = [];
        foreach ($rawEvents as $event) {
            $eventId = $event->id ?? '';
            $senderPubkey = $event->pubkey ?? '';
            $isHidden = in_array($eventId, $moderation['hidden'], true);

            $isReply = false;
            $replyToEventId = null;
            foreach ($event->tags ?? [] as $tag) {
                if (($tag[0] ?? '') === 'e' && isset($tag[3]) && $tag[3] === 'reply') {
                    $isReply = true;
                    $replyToEventId = $tag[1] ?? null;
                    break;
                }
            }

            $messages[] = new ChatMessageDto(
                eventId: $eventId,
                senderPubkey: $senderPubkey,
                senderDisplayName: $nameMap[$senderPubkey] ?? substr($senderPubkey, 0, 12) . '…',
                content: $event->content ?? '',
                createdAt: $event->created_at ?? 0,
                isReply: $isReply,
                replyToEventId: $replyToEventId,
                isHidden: $isHidden,
            );
        }

        usort($messages, fn(ChatMessageDto $a, ChatMessageDto $b) => $a->createdAt <=> $b->createdAt);

        return $messages;
    }

    /**
     * Low-level: send a REQ to the chat relay and collect events until EOSE.
     *
     * @return object[] Raw decoded event objects
     */
    private function query(array $filter, ?ChatCommunity $community = null): array
    {
        $relayUrl = $this->getRelayUrl($community);
        $subId = 'chat_' . bin2hex(random_bytes(8));

        try {
            $relay = new Relay($relayUrl);
            $client = $relay->getClient();
            if (method_exists($client, 'setTimeout')) {
                $client->setTimeout(10);
            }

            // Build REQ message: ["REQ", "subId", {filter}]
            $reqPayload = json_encode(['REQ', $subId, $filter]);
            $client->text($reqPayload);

            $events = [];
            $maxMessages = ($filter['limit'] ?? 50) + 20; // safety margin
            $count = 0;

            while ($count < $maxMessages) {
                $response = $client->receive();
                if ($response === null) {
                    break;
                }

                $decoded = json_decode($response->getContent());
                if (!is_array($decoded)) {
                    break;
                }

                $type = $decoded[0] ?? '';

                if ($type === 'EVENT' && isset($decoded[2])) {
                    $events[] = $decoded[2];
                } elseif ($type === 'EOSE') {
                    break;
                } elseif ($type === 'CLOSED' || $type === 'NOTICE') {
                    break;
                }

                $count++;
            }

            // Send CLOSE
            $client->text(json_encode(['CLOSE', $subId]));
            $client->disconnect();

            return $events;
        } catch (\Throwable $e) {
            $this->logger?->error('ChatRelayClient: query failed', [
                'relay' => $relayUrl,
                'filter' => $filter,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}

