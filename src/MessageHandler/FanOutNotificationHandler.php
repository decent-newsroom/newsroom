<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Notification;
use App\Message\FanOutNotificationMessage;
use App\Repository\EventRepository;
use App\Repository\NotificationRepository;
use App\Service\Mercure\MercureSubscriberTokenService;
use App\Service\Notification\NotificationMatcher;
use App\Service\Notification\NotificationRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Resolves the recipient set for an ingested Nostr event and, for each matching
 * user, (1) persists a {@see Notification} row (dedup by `(user_id, event_id)`)
 * and (2) publishes a Mercure update to that user's private notifications topic.
 *
 * Short-circuits on any kind outside {@see NotificationMatcher::NOTIFIED_KINDS}
 * — defence in depth even if an upstream filter over-matches.
 */
#[AsMessageHandler]
class FanOutNotificationHandler
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly NotificationRepository $notificationRepository,
        private readonly NotificationMatcher $matcher,
        private readonly NotificationRenderer $renderer,
        private readonly EntityManagerInterface $em,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(FanOutNotificationMessage $message): void
    {
        $event = $this->eventRepository->find($message->getEventId());
        if ($event === null) {
            $this->logger->debug('FanOutNotification: event not found', ['event_id' => $message->getEventId()]);
            return;
        }

        if (!NotificationMatcher::isNotifiedKind($event->getKind())) {
            // Defence in depth: refuse to notify on out-of-scope kinds.
            return;
        }

        $matches = $this->matcher->match($event);
        if ($matches === []) {
            return;
        }

        $rendered = $this->renderer->render($event);
        $createdAt = (new \DateTimeImmutable())->setTimestamp($event->getCreatedAt());
        $coordinate = NotificationMatcher::coordinateOf($event);

        foreach ($matches as $subscription) {
            $user = $subscription->getUser();

            // Dedup: (user_id, event_id) unique constraint handles races, but
            // we also skip cleanly to avoid flush-then-rollback churn.
            if ($this->notificationRepository->existsForUserAndEvent($user, $event->getId())) {
                continue;
            }

            $notification = new Notification(
                user: $user,
                eventId: $event->getId(),
                eventKind: $event->getKind(),
                eventPubkey: $event->getPubkey(),
                url: $rendered['url'],
                createdAt: $createdAt,
                subscription: $subscription,
                eventCoordinate: $coordinate,
                title: $rendered['title'],
                summary: $rendered['summary'],
            );

            try {
                $this->em->persist($notification);
                $this->em->flush();
            } catch (\Throwable $e) {
                // Unique-violation race with another worker — treat as delivered.
                if (str_contains($e->getMessage(), '23505') || str_contains($e->getMessage(), 'duplicate key')) {
                    $this->logger->debug('Notification insert raced; skipping mercure publish', [
                        'user_id' => $user->getId(),
                        'event_id' => $event->getId(),
                    ]);
                    continue;
                }
                $this->logger->error('Failed to persist notification', [
                    'user_id' => $user->getId(),
                    'event_id' => $event->getId(),
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $this->publishToUser($user, $notification);
        }
    }

    private function publishToUser(\App\Entity\User $user, Notification $notification): void
    {
        try {
            $topic = MercureSubscriberTokenService::topicForUser($user);
            $payload = json_encode([
                'type' => 'notification',
                'id' => $notification->getId(),
                'kind' => $notification->getEventKind(),
                'title' => $notification->getTitle(),
                'summary' => $notification->getSummary(),
                'url' => $notification->getUrl(),
                'author' => $notification->getEventPubkey(),
                'createdAt' => $notification->getCreatedAt()->getTimestamp(),
                'unread' => $this->notificationRepository->countUnreadForUser($user),
            ], JSON_UNESCAPED_SLASHES);

            $this->hub->publish(new Update($topic, (string) $payload, true));
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to publish notification to Mercure', [
                'user_id' => $user->getId(),
                'notification_id' => $notification->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}

