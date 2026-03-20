<?php

declare(strict_types=1);

namespace App\ChatBundle\MessageHandler;

use App\ChatBundle\Message\SendChatPushNotificationMessage;
use App\ChatBundle\Repository\ChatGroupRepository;
use App\ChatBundle\Repository\ChatPushSubscriptionRepository;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SendChatPushNotificationHandler
{
    public function __construct(
        private readonly ChatGroupRepository $groupRepo,
        private readonly ChatPushSubscriptionRepository $subscriptionRepo,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $vapidSubject,
        private readonly string $vapidPublicKey,
        private readonly string $vapidPrivateKey,
    ) {}

    public function __invoke(SendChatPushNotificationMessage $message): void
    {
        if ($this->vapidPublicKey === '' || $this->vapidPrivateKey === '') {
            return; // Push not configured
        }

        $group = $this->groupRepo->find($message->groupId);
        if ($group === null) {
            return;
        }

        // Throttle: at most one push per group per 30 seconds
        $throttleKey = 'chat_push_cooldown_' . $message->groupId;
        $item = $this->cache->getItem($throttleKey);
        if ($item->isHit()) {
            return;
        }

        $subscriptions = $this->subscriptionRepo->findForGroupNotification($group, $message->senderPubkey);
        if (empty($subscriptions)) {
            return;
        }

        $auth = [
            'VAPID' => [
                'subject' => $this->vapidSubject,
                'publicKey' => $this->vapidPublicKey,
                'privateKey' => $this->vapidPrivateKey,
            ],
        ];

        try {
            $webPush = new WebPush($auth);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to initialize WebPush: ' . $e->getMessage());
            return;
        }

        $payload = json_encode([
            'type' => 'chat_message',
            'groupSlug' => $message->groupSlug,
            'groupName' => $message->groupName,
            'communitySubdomain' => $message->communitySubdomain,
            'senderDisplayName' => $message->senderDisplayName,
        ]);

        foreach ($subscriptions as $pushSub) {
            $subscription = Subscription::create([
                'endpoint' => $pushSub->getEndpoint(),
                'publicKey' => $pushSub->getPublicKey(),
                'authToken' => $pushSub->getAuthToken(),
            ]);

            $webPush->queueNotification($subscription, $payload);
        }

        // Flush and handle stale subscriptions
        foreach ($webPush->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                $this->subscriptionRepo->deleteByEndpoint($report->getEndpoint());
                $this->logger->info('Removed expired push subscription: ' . $report->getEndpoint());
            } elseif (!$report->isSuccess()) {
                $this->logger->warning('Push notification failed for endpoint: ' . $report->getEndpoint() . ' — ' . $report->getReason());
            }
        }

        // Set throttle
        $item->set(true);
        $item->expiresAfter(30);
        $this->cache->save($item);
    }
}


