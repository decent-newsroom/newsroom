<?php

declare(strict_types=1);

namespace App\Bookshelf;

use App\Entity\Event;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventWorkspaceScopePolicy;
use App\Service\Reader\ContentAuthorAccessPolicy;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Public signed indexes only: never Article projections, drafts, or remote reads. */
final readonly class BookshelfIndexSource
{
    public function __construct(
        private EventRepository $events,
        private EventPresentationFactory $presentations,
        private ContentAuthorAccessPolicy $authors,
        private HiddenCoordinateRepository $hidden,
    ) {}

    public function read(string $eventId): Event
    {
        $event = $this->events->findOneBy(['id' => $eventId, 'kind' => 30040]);
        if (!$event instanceof Event || $event->getKind() !== 30040 || $event->getId() !== $eventId
            || EventWorkspaceScopePolicy::isScopedInventoryList($event->getKind(), $event->getTags())) {
            throw new NotFoundHttpException();
        }
        $coordinate = $this->presentations->fromAuthorizedEvent($event)->coordinate;
        if ($coordinate === null || $this->hidden->isHidden($coordinate)) {
            throw new NotFoundHttpException();
        }
        $this->authors->assertReadable($event->getPubkey());

        return $event;
    }
}
