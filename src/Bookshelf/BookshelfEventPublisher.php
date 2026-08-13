<?php

declare(strict_types=1);

namespace App\Bookshelf;

use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\UserRelayListService;
use DecentNewsroom\BookshelfBundle\Contract\DirectoryEventPublisherInterface;
use swentel\nostr\Event\Event as NostrEvent;


final class BookshelfEventPublisher implements DirectoryEventPublisherInterface
{
    public function __construct(
        private readonly GenericEventProjector $eventProjector,
        private readonly UserRelayListService $userRelayListService,
        private readonly NostrClient $nostrClient,
    ) {
    }

    public function publish(object $rawEvent, string $pubkeyHex): int
    {
        $this->eventProjector->projectEventFromNostrEvent($rawEvent, 'local');

        $event = new NostrEvent();
        $event->setId((string) $rawEvent->id);
        $event->setPublicKey((string) $rawEvent->pubkey);
        $event->setCreatedAt((int) $rawEvent->created_at);
        $event->setKind((int) $rawEvent->kind);
        $event->setTags($rawEvent->tags);
        $event->setContent((string) $rawEvent->content);
        $event->setSignature((string) $rawEvent->sig);

        $relays = $this->userRelayListService->getRelaysForPublishing($pubkeyHex);
        $relayResults = $this->nostrClient->publishEvent($event, $relays, 10);

        $successCount = 0;
        foreach ($relayResults as $result) {
            if ($result === true || (is_object($result) && ($result->type ?? null) === 'OK')) {
                $successCount++;
            }
        }

        return $successCount;
    }
}
