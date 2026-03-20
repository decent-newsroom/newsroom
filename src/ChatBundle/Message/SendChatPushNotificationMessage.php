<?php

declare(strict_types=1);

namespace App\ChatBundle\Message;

/**
 * Async message dispatched after a chat message is sent.
 * Carries only metadata — no message content for privacy.
 */
class SendChatPushNotificationMessage
{
    public function __construct(
        public readonly int $groupId,
        public readonly string $senderPubkey,
        public readonly string $senderDisplayName,
        public readonly string $groupName,
        public readonly string $groupSlug,
        public readonly string $communitySubdomain,
    ) {}
}

