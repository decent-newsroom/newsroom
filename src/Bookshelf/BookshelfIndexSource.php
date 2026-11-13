<?php

declare(strict_types=1);

namespace App\Bookshelf;

use App\Entity\Event;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\Reader\ContentAuthorAccessPolicy;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Public signed indexes only: never Article projections, drafts, or remote reads. */
final readonly class BookshelfIndexSource
{
    public function __construct(
        private EventRepository $events,
        private EventPresentationFactory $presentations,
        private ContentAuthorAccessPolicy $authors,
        private DeletedEventRepository $deletions,
        private HiddenCoordinateRepository $hidden,
    ) {}

    /** @param list<string> $mutedPubkeys */
    public function read(string $eventId, array $mutedPubkeys): Event
    {
        $event = $this->events->findOneBy(['id' => $eventId, 'kind' => 30040]);
        if (!$event instanceof Event || $event->getKind() !== 30040 || $event->getId() !== $eventId) {
            throw new NotFoundHttpException();
        }
        $coordinate = $this->presentations->fromAuthorizedEvent($event)->coordinate;
        if ($coordinate === null
            || in_array(strtolower($event->getPubkey()), array_map('strtolower', $mutedPubkeys), true)
            || $this->hidden->isHidden($coordinate)
            || $this->deletions->isSuppressed(
                $event->getId(), $event->getKind(), $event->getPubkey(),
                explode(':', $coordinate, 3)[2], $event->getCreatedAt(),
            )) {
            throw new NotFoundHttpException();
        }
        $this->authors->assertReadable($event->getPubkey());

        return $event;
    }
}
