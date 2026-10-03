<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RefreshReaderInteractionsMessage;
use App\Repository\DeletedEventRepository;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrEventVerifier;
use App\Unfold\InteractionHydrator;
use DecentNewsroom\UnfoldBundle\Config\ContentReference;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RefreshReaderInteractionsHandler
{
    public function __construct(
        private InteractionHydrator $hydrator,
        private NostrClient $nostr,
        private GenericEventProjector $projector,
        private NostrEventVerifier $verifier,
        private DeletedEventRepository $deletedEvents,
    ) {
    }

    public function __invoke(RefreshReaderInteractionsMessage $message): void
    {
        // Never trust a target serialized before the worker started.
        $target = $this->hydrator->target($message->publicationCoordinate, $message->coordinate);
        if ($target === null || $this->deletedEvents->isSuppressed(
            $target->post->eventId, $target->post->kind, $target->post->pubkey,
            ContentReference::fromInput($target->post->coordinate)->identifier,
            $target->original?->createdAt ?? $target->post->publishedAt,
        )) {
            return;
        }
        $references = [$target->post->coordinate, ...$this->hydrator->revisionReferences($target)];
        $events = [];
        $parentIds = [];
        foreach ($this->hydrator->thread($target)->comments as $comment) {
            if ($comment->kind === 1111) {
                $parentIds[$comment->id] = $comment->createdAt;
            }
        }
        foreach ($references as $reference) {
            foreach (array_slice($this->nostr->getComments($reference, null, $target->post->pubkey), 0, 500) as $event) {
                $verified = $this->verifiedEvent($event);
                if ($verified !== null) {
                    $events[$verified->id] = $verified;
                    if ($verified->kind === 1111) {
                        $parentIds[$verified->id] = $verified->created_at;
                    }
                }
            }
        }
        arsort($parentIds);
        foreach (array_slice(array_keys($parentIds), 0, 40) as $parentId) {
            foreach (array_slice($this->nostr->getComments($parentId, null, $target->post->pubkey), 0, 500) as $event) {
                $verified = $this->verifiedEvent($event);
                if ($verified !== null && $verified->kind === 1111) {
                    $events[$verified->id] = $verified;
                }
            }
        }
        foreach ($events as $event) {
            $this->projector->projectEventFromNostrEvent($event, $target->relayHint ?? '');
        }
    }

    private function verifiedEvent(mixed $event): ?object
    {
        if (!is_object($event) || !is_string($event->id ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $event->id) !== 1
            || !is_string($event->pubkey ?? null) || preg_match('/^[a-f0-9]{64}$/D', $event->pubkey) !== 1
            || !is_string($event->sig ?? null) || preg_match('/^[a-f0-9]{128}$/D', $event->sig) !== 1
            || !is_int($event->created_at ?? null) || $event->created_at < 0
            || !is_string($event->content ?? null) || !is_array($event->tags ?? null) || !array_is_list($event->tags)
            || !in_array($event->kind ?? null, [1111, 7, 16, 9735], true)) {
            return null;
        }
        foreach ($event->tags as $tag) {
            if (!is_array($tag) || !array_is_list($tag) || $tag === []) {
                return null;
            }
            foreach ($tag as $value) {
                if (!is_string($value)) {
                    return null;
                }
            }
        }
        try {
            $core = $this->verifier->fromArray((array) $event);
        } catch (\InvalidArgumentException | \TypeError) {
            return null;
        }
        if (!$this->verifier->verify($core)) {
            return null;
        }
        $tags = $event->tags ?? [];
        $identifier = null;
        foreach ($tags as $tag) {
            if (($tag[0] ?? null) === 'd') {
                $identifier = $tag[1] ?? null;
            }
            if (($tag[0] ?? null) === 's') {
                return null;
            }
        }
        if ($this->deletedEvents->isSuppressed($event->id, $event->kind, $event->pubkey, $identifier, $event->created_at)) {
            return null;
        }
        return $event;
    }
}
