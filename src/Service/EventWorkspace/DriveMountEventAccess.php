<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

use App\Dto\EventWorkspace\EventPresentation;
use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;

/**
 * Exact local target access for read-only NKBIP drive event opening.
 *
 * Resolution is bounded to one visible directory page. It never follows relay
 * hints, loads from relays, persists data, or renders Article HTML.
 */
final readonly class DriveMountEventAccess implements DriveMountEventAccessInterface
{
    private const OPENABLE_KINDS = [1, 20, 21, 22, 30023, 30040, 30041];

    public function __construct(
        private EventRepository $events,
        private ArticleRepository $articles,
        private EventPreviewAccessPolicy $eventAccess,
    ) {}

    /**
     * Resolve safely-openable Article occurrences in a directory page.
     *
     * The generic target preview resolver intentionally excludes Articles.
     * This narrow path adds only kind 30023 after the Article projection's
     * existing entitlement and event visibility policy both pass.
     *
     * @param list<\App\Dto\EventWorkspace\EventReference> $references
     * @return array<int, Event> source tag position => authorized metadata-only event
     */
    public function readableArticleTargets(array $references): array
    {
        $coordinates = [];
        $eventIds = [];
        foreach ($references as $reference) {
            if ($reference->role !== \App\Dto\EventWorkspace\EventReferenceRole::Content
                || $reference->status !== \App\Dto\EventWorkspace\EventReferenceStatus::Unresolved) {
                continue;
            }
            if ($reference->type === \App\Dto\EventWorkspace\EventReferenceType::Address
                && $reference->targetKind === KindsEnum::LONGFORM->value
                && $reference->target !== null) {
                if ($reference->versionEventId !== null) {
                    $eventIds[] = $reference->versionEventId;
                } elseif ($reference->versionEventId === null) {
                    $coordinates[] = $reference->target;
                }
            } elseif ($reference->type === \App\Dto\EventWorkspace\EventReferenceType::EventId
                && $reference->target !== null) {
                $eventIds[] = $reference->target;
            }
        }
        if (count($coordinates) + count($eventIds) > 50) {
            throw new \InvalidArgumentException('Drive Article opening is limited to one directory page.');
        }

        $targets = $this->events->findWorkspaceDriveArticleTargets(
            array_values(array_unique($coordinates)),
            array_values(array_unique($eventIds)),
        );
        $articlesByEventId = [];
        $result = [];
        foreach ($references as $reference) {
            if ($reference->role !== \App\Dto\EventWorkspace\EventReferenceRole::Content
                || $reference->status !== \App\Dto\EventWorkspace\EventReferenceStatus::Unresolved) {
                continue;
            }
            $event = match ($reference->type) {
                \App\Dto\EventWorkspace\EventReferenceType::EventId =>
                    $targets['eventIds'][$reference->target ?? ''] ?? null,
                \App\Dto\EventWorkspace\EventReferenceType::Address =>
                    $reference->versionEventId !== null
                        ? ($targets['eventIds'][$reference->versionEventId] ?? null)
                        : ($targets['coordinates'][$reference->target ?? ''] ?? null),
                default => null,
            };
            if (!$event instanceof Event
                || $event->getKind() !== KindsEnum::LONGFORM->value
                || !$this->matchesReference($event, $reference)) {
                continue;
            }
            $eventId = strtolower($event->getId());
            if (!array_key_exists($eventId, $articlesByEventId)) {
                $article = $this->articles->findWorkspaceEntitlementByEventId($eventId);
                $articlesByEventId[$eventId] = $article instanceof Article ? $article : null;
            }
            $article = $articlesByEventId[$eventId];
            $identifier = $this->firstDTag($event);
            if (!$article instanceof Article
                || $identifier === null
                || !$this->isSafeEvent($event)
                || !$this->eventAccess->isReadable($event)
                || !$this->articleAgreesWithEvent($article, $event, $identifier)
                || !$this->eventAccess->isArticleReadable($article, strtolower($event->getPubkey()), $identifier)) {
                continue;
            }
            $result[$reference->position] = $event;
        }

        return $result;
    }

    /**
     * Reauthorize a complete local Event on every exact-open request.
     */
    public function isReadableTarget(Event $event): bool
    {
        if (!$this->isSafeEvent($event) || !$this->eventAccess->isReadable($event)) {
            return false;
        }
        if ($event->getKind() !== KindsEnum::LONGFORM->value) {
            return true;
        }

        $identifier = $this->firstDTag($event);
        if ($identifier === null) {
            return false;
        }
        $article = $this->articles->findWorkspaceEntitlementByEventId(strtolower($event->getId()));

        return $article instanceof Article
            && $this->articleAgreesWithEvent($article, $event, $identifier)
            && $this->eventAccess->isArticleReadable($article, strtolower($event->getPubkey()), $identifier);
    }

    private function articleAgreesWithEvent(Article $article, Event $event, string $identifier): bool
    {
        return strtolower((string) $article->getEventId()) === strtolower($event->getId())
            && strtolower((string) $article->getPubkey()) === strtolower($event->getPubkey())
            && $article->getSlug() === $identifier
            && $article->getCreatedAt()?->getTimestamp() === $event->getCreatedAt();
    }

    private function isSafeEvent(Event $event): bool
    {
        return in_array($event->getKind(), self::OPENABLE_KINDS, true)
            && !EventPresentation::hasSensitiveTagEvidence($event->getTags())
            && !EventWorkspaceScopePolicy::isScopedTarget($event->getKind(), $event->getTags());
    }

    private function matchesReference(
        Event $event,
        \App\Dto\EventWorkspace\EventReference $reference,
    ): bool {
        if ($reference->type === \App\Dto\EventWorkspace\EventReferenceType::EventId) {
            return $reference->target !== null
                && strtolower($reference->target) === strtolower($event->getId());
        }
        if ($reference->type !== \App\Dto\EventWorkspace\EventReferenceType::Address
            || $reference->targetKind !== $event->getKind()
            || strtolower((string) $reference->targetPubkey) !== strtolower($event->getPubkey())) {
            return false;
        }
        $identifier = $this->firstDTag($event);
        if ($identifier === null || $reference->targetIdentifier !== $identifier) {
            return false;
        }

        return $reference->versionEventId === null
            || strtolower($reference->versionEventId) === strtolower($event->getId());
    }

    private function firstDTag(Event $event): ?string
    {
        foreach ($event->getTags() as $tag) {
            if (!is_array($tag) || ($tag[0] ?? null) !== 'd') {
                continue;
            }

            return is_string($tag[1] ?? null) ? $tag[1] : null;
        }

        return null;
    }
}
