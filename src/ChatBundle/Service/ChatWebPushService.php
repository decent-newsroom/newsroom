<?php

declare(strict_types=1);

namespace App\ChatBundle\Service;

use App\ChatBundle\Entity\ChatGroup;
use App\ChatBundle\Entity\ChatUser;
use App\ChatBundle\Message\SendChatPushNotificationMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches push notification messages asynchronously via Messenger.
 */
class ChatWebPushService
{
    public function __construct(
        private readonly MessageBusInterface $bus,
    ) {}

    public function dispatchPushNotification(ChatGroup $group, ChatUser $sender): void
    {
        $community = $group->getCommunity();

        $this->bus->dispatch(new SendChatPushNotificationMessage(
            groupId: $group->getId(),
            senderPubkey: $sender->getPubkey(),
            senderDisplayName: $sender->getDisplayName(),
            groupName: $group->getName(),
            groupSlug: $group->getSlug(),
            communitySubdomain: $community->getSubdomain(),
        ));
    }
}

