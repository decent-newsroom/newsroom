<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Update;
use App\Message\FanOutUpdateMessage;
use App\Repository\EventRepository;
use App\Repository\UpdateRepository;
use App\Repository\SubscriptionChangeRepository;
use App\Service\Mercure\MercureSubscriberTokenService;
use App\Service\Update\UpdateMatcher;
use App\Service\Update\UpdateRenderer;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\EventWorkspace\EventWorkspaceScopePolicy;
use App\Dto\EventWorkspace\EventPresentation;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update as MercureUpdate;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Resolves the recipient set for an ingested Nostr event and, for each matching
 * user, (1) persists a {@see Update} row (dedup by `(user_id, event_id)`)
 * and (2) publishes a Mercure update to that user's private updates topic.
 *
 * Short-circuits on any kind outside {@see UpdateMatcher::NOTIFIED_KINDS}
 * — defence in depth even if an upstream filter over-matches.
 */
#[AsMessageHandler]
class FanOutUpdateHandler
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly SubscriptionChangeRepository $changes,
        private readonly UpdateRepository $updateRepository,
        private readonly UpdateMatcher $matcher,
        private readonly UpdateRenderer $renderer,
        private readonly EntityManagerInterface $em,
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
        private readonly EventPreviewAccessPolicy $access,
    ) {
    }

    public function __invoke(FanOutUpdateMessage $message): void
    {
        $change = $this->changes->find($message->getEventId());
        $event = $change?->metadataEvent() ?? $this->eventRepository->find($message->getEventId());
        if ($event === null) {
            $this->logger->debug('Subscription fan-out: event not found', ['event_id' => $message->getEventId()]);
            return;
        }

        if ($change !== null && !$change->hasChanges()
            && ($change->getKind() !== 30023 || $change->getPreviousEventId() !== null)) {
            return;
        }
        if (!UpdateMatcher::isNotifiedKind($event->getKind())) {
            // Defence in depth: refuse to update on out-of-scope kinds.
            return;
        }
        if (EventPresentation::hasSensitiveTagEvidence($event->getTags())
            || EventWorkspaceScopePolicy::isScopedInventoryList($event->getKind(), $event->getTags())) {
            return;
        }

        $matches = $this->matcher->match($event);
        if ($matches === []) {
            return;
        }

        $rendered = $this->renderer->render($event);
        $createdAt = (new \DateTimeImmutable())->setTimestamp($event->getCreatedAt());
        $coordinate = UpdateMatcher::coordinateOf($event);

        foreach ($matches as $subscription) {
            $user = $subscription->getUser();
            if (!$subscription->isActive() || !$this->access->isReadable($event, $user)) {
                continue;
            }
            if ($subscription->getCreatedAt()->getTimestamp() > $event->getCreatedAt()) {
                continue;
            }

            // Dedup: (user_id, event_id) unique constraint handles races, but
            // we also skip cleanly to avoid flush-then-rollback churn.
            if ($this->updateRepository->existsForUserAndEvent($user, $event->getId())) {
                continue;
            }

            $update = new Update(
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
                change: $change,
            );

            try {
                $inserted = $this->em->getConnection()->transactional(function () use ($user, $event, $update): bool {
                    if ($this->em->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                        $this->em->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(hashtext(:delivery))', [
                            'delivery' => $user->getId() . ':' . $event->getId(),
                        ]);
                    }
                    if ($this->updateRepository->existsForUserAndEvent($user, $event->getId())) {
                        return false;
                    }
                    $this->em->persist($update);
                    $this->em->flush();
                    return true;
                });
            } catch (\Throwable $e) {
                $this->logger->error('Failed to persist subscription update', [
                    'user_id' => $user->getId(),
                    'event_id' => $event->getId(),
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            if ($inserted) {
                $this->publishToUser($user, $update);
            }
        }
    }

    private function publishToUser(\App\Entity\User $user, Update $update): void
    {
        try {
            $topic = MercureSubscriberTokenService::topicForUser($user);
            $payload = json_encode([
                'type' => 'update',
            ], JSON_UNESCAPED_SLASHES);

            $this->hub->publish(new MercureUpdate($topic, (string) $payload, true));
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to publish update to Mercure', [
                'user_id' => $user->getId(),
                'update_id' => $update->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
