<?php

declare(strict_types=1);

namespace App\Bookshelf;

use App\Dto\EventWorkspace\EventReferenceStatus;
use App\Entity\Event;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\ContentAuthorAccessPolicy;

final readonly class BookContainingIndexes
{
    public function __construct(
        private EventRepository $events,
        private EventPresentationFactory $presentations,
        private ContentAuthorAccessPolicy $authors,
        private DeletedEventRepository $deletions,
        private HiddenCoordinateRepository $hidden,
        private NostrNip19Service $nip19,
    ) {}

    /**
     * @param list<string> $mutedPubkeys
     * @return list<array{title: string, naddr: string}>
     */
    public function forBook(Event $book, array $mutedPubkeys = []): array
    {
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
        $result = [];
        foreach ($parents as $parent) {
            $presentation = $this->presentations->fromAuthorizedEvent($parent);
            if ($presentation->coordinate === null
                || in_array($parent->getPubkey(), $suppressed, true)
                || in_array($parent->getPubkey(), $mutedPubkeys, true)
                || in_array($parent->getId(), $deleted, true)
                || $this->hidden->isHidden($presentation->coordinate)
                || $presentation->isSensitive) {
                continue;
            }
            foreach ($presentation->references as $reference) {
                if ($reference->tagName !== 'a' || $reference->target !== $identity
                    || $reference->status !== EventReferenceStatus::Unresolved
                    || ($reference->versionEventId !== null && $reference->versionEventId !== $book->getId())) {
                    continue;
                }
                $result[] = [
                    'title' => $presentation->title ?? $parent->getId(),
                    'naddr' => $this->nip19->encodeAddr(
                        $parent->getPubkey(),
                        explode(':', $presentation->coordinate, 3)[2],
                        30040,
                    ),
                ];
                break;
            }
        }

        return $result;
    }
}
