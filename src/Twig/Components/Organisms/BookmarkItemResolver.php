<?php

declare(strict_types=1);

namespace App\Twig\Components\Organisms;

use App\Entity\Event;
use App\Repository\EventRepository;
use App\Service\Nostr\NostrClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Resolves a single bookmarked item (event ref, coordinate, pubkey, or tag)
 * and renders the appropriate preview card.
 *
 * For 'e'-type items a lazy fetch from relays is triggered via a LiveAction
 * when the event is not yet in the local DB.
 */
#[AsLiveComponent('Organisms:BookmarkItemResolver')]
final class BookmarkItemResolver
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $type = '';   // e, a, p, t

    #[LiveProp]
    public string $value = '';  // event id, coordinate, pubkey hex, or tag string

    #[LiveProp]
    public ?string $relay = null;

    #[LiveProp(writable: true)]
    public bool $fetched = false;

    /** Resolved Event entity for 'e'-type items */
    public ?Event $event = null;

    /** Error message when resolution fails */
    public ?string $error = null;

    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly NostrClient $nostrClient,
        private readonly LoggerInterface $logger,
    ) {}

    public function mount(): void
    {
        if ($this->type === 'e' && $this->value) {
            try {
                $this->event = $this->eventRepository->findById($this->value);
            } catch (\Throwable $e) {
                $this->logger->warning('BookmarkItemResolver: failed to load event from DB', [
                    'eventId' => $this->value,
                    'error' => $e->getMessage(),
                ]);
                $this->error = 'Failed to load event';
            }
        }
    }

    /**
     * Fetch the event from relays when it's not in the local DB.
     * Called by the user clicking "Fetch" in the template.
     */
    #[LiveAction]
    public function fetchEvent(): void
    {
        if ($this->type !== 'e' || !$this->value) {
            return;
        }

        try {
            // Already resolved?
            $this->event = $this->eventRepository->findById($this->value);
            if ($this->event) {
                $this->fetched = true;
                return;
            }

            $relays = $this->relay ? [$this->relay] : [];
            $raw = $this->nostrClient->getEventById($this->value, $relays);

            if (!$raw) {
                $this->error = 'Event not found on relays';
                $this->fetched = true;
                return;
            }

            // Persist so future loads are instant
            $event = new Event();
            $event->setId($raw->id);
            $event->setPubkey($raw->pubkey);
            $event->setKind($raw->kind);
            $event->setContent($raw->content ?? '');
            $event->setTags($raw->tags ?? []);
            $event->setCreatedAt($raw->created_at);
            $event->setSig($raw->sig ?? '');
            $event->extractAndSetDTag();

            $em = $this->entityManager;
            $em->persist($event);
            $em->flush();

            $this->event = $event;
            $this->fetched = true;
        } catch (\Throwable $e) {
            $this->logger->warning('BookmarkItemResolver: relay fetch failed', [
                'eventId' => $this->value,
                'error' => $e->getMessage(),
            ]);
            $this->error = 'Failed to fetch event';
            $this->fetched = true;
        }
    }
}




