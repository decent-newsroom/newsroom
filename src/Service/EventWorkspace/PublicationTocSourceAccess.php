<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

use App\Entity\Event;
use App\Repository\EventRepository;
use App\Repository\DeletedEventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\Reader\ContentAuthorAccessPolicy;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Reauthorize the owner root and each exact source on every discovery poll/action. */
final readonly class PublicationTocSourceAccess
{
    public function __construct(
        private EventRepository $events,
        private EventPresentationFactory $presentations,
        private DeletedEventRepository $deletions,
        private HiddenCoordinateRepository $hidden,
        private ContentAuthorAccessPolicy $authors,
    ) {}

    /** @param list<string> $ancestors @param list<string> $muted */
    public function authorize(string $reader, string $sourceId, array $ancestors, array $muted): Event
    {
        $path = [...$ancestors, $sourceId];
        if (count($path) > PublicationTocPage::MAX_EXPANSION_DEPTH || count(array_unique($path)) !== count($path)) {
            throw new NotFoundHttpException();
        }
        $previous = null;
        foreach ($path as $index => $id) {
            $event = $this->events->findById($id);
            if (!$event instanceof Event || $event->getKind() !== 30040
                || EventWorkspaceScopePolicy::isScopedInventoryList($event->getKind(), $event->getTags())
                || ($index === 0 && strtolower($event->getPubkey()) !== $reader)) {
                throw new NotFoundHttpException();
            }
            $coordinate = $this->presentations->fromAuthorizedEvent($event)->coordinate;
            $this->assertTargetReadable($event->getPubkey(), (string) $coordinate, $event->getId(), $event->getCreatedAt(), $muted);
            if ($previous !== null) {
                $linked = false;
                foreach ($this->presentations->fromAuthorizedEvent($previous)->references as $reference) {
                    if ($reference->role->value === 'content' && $reference->type->value === 'address'
                        && $reference->status->value === 'unresolved' && $reference->target === $coordinate
                        && ($reference->versionEventId === null || $reference->versionEventId === $event->getId())) {
                        $linked = true;
                        break;
                    }
                }
                if (!$linked) {
                    throw new NotFoundHttpException();
                }
            }
            $previous = $event;
        }
        return $previous;
    }

    /** @param list<string> $muted */
    public function assertTargetReadable(string $author, string $coordinate, string $id, int $createdAt, array $muted): void
    {
        $author = strtolower($author);
        if (in_array($author, array_map('strtolower', $muted), true)
            || $this->hidden->findHiddenCoordinates([$coordinate]) !== []) {
            throw new NotFoundHttpException();
        }
        $this->authors->assertReadable($author);
        [$kind, , $identifier] = explode(':', $coordinate, 3);
        if ($this->deletions->isSuppressed($id, (int) $kind, $author, $identifier, $createdAt)) {
            throw new NotFoundHttpException();
        }
    }
}
