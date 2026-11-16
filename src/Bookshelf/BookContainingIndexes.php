<?php

declare(strict_types=1);

namespace App\Bookshelf;

use App\Dto\EventWorkspace\EventReferenceStatus;
use App\Entity\Event;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventWorkspaceScopePolicy;
use App\Service\Reader\ContentAuthorAccessPolicy;

final readonly class BookContainingIndexes
{
    public function __construct(
        private EventRepository $events,
        private EventPresentationFactory $presentations,
        private ContentAuthorAccessPolicy $authors,
        private DeletedEventRepository $deletions,
        private HiddenCoordinateRepository $hidden,
    ) {}

    /**
     * Find local parents of any kind-30040 publication index, including books.
     *
     * @param list<string> $mutedPubkeys
     * @return list<array{title: string, eventId: string}>
     */
    public function forBook(Event $book, array $mutedPubkeys = []): array
    {
        if (EventWorkspaceScopePolicy::isScopedInventoryList($book->getKind(), $book->getTags())) {
            return [];
        }
        $identity = $this->presentations->fromAuthorizedEvent($book)->coordinate;
        if ($identity === null) {
            return [];
        }

        $coordinates = [];
        foreach ($this->events->findReferencingEvents('a', $identity, [30040], 50, newestFirst: true) as $candidate) {
            $coordinate = $this->presentations->fromAuthorizedEvent($candidate)->coordinate;
            if ($coordinate !== null && $coordinate !== $identity) {
                $coordinates[] = $coordinate;
            }
        }
        if ($coordinates === []) {
            return [];
        }
        $targets = $this->events->findPublicationIndexTargets(array_values(array_unique($coordinates)), []);
        $parents = array_values($targets['coordinates']);
        $suppressed = $this->authors->suppressedPubkeys(array_values(array_unique(array_map(
            static fn (Event $event): string => $event->getPubkey(),
            $parents,
        ))));
        $deleted = $this->deletions->findSuppressedEventIds($parents);
        $mutedPubkeys = array_map('strtolower', $mutedPubkeys);
        $result = [];
        foreach ($parents as $parent) {
            if (preg_match('/^[0-9a-f]{64}$/D', $parent->getId()) !== 1
                || EventWorkspaceScopePolicy::isScopedTarget($parent->getKind(), $parent->getTags())) {
                continue;
            }
            $presentation = $this->presentations->fromAuthorizedEvent($parent);
            $parentPubkey = strtolower($parent->getPubkey());
            if ($presentation->coordinate === null
                || in_array($parentPubkey, $suppressed, true)
                || in_array($parentPubkey, $mutedPubkeys, true)
                || in_array(strtolower($parent->getId()), $deleted, true)
                || $this->hidden->isHidden($presentation->coordinate)
                || $presentation->isSensitive) {
                continue;
            }
            foreach ($presentation->references as $reference) {
                if ($reference->tagName !== 'a' || $reference->target !== $identity
                    || $reference->status !== EventReferenceStatus::Unresolved
                    || ($reference->versionEventId !== null && $reference->versionEventId !== strtolower($book->getId()))) {
                    continue;
                }
                $result[] = [
                    'title' => $presentation->title ?? $parent->getId(),
                    'eventId' => $parent->getId(),
                ];
                break;
            }
        }

        return $result;
    }
}
