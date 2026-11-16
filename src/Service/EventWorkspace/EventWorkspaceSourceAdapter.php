<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

use App\Dto\EventWorkspace\EventWorkspaceIdentity;
use App\Dto\EventWorkspace\EventWorkspaceSource;
use App\Dto\EventWorkspace\EventWorkspaceSourceRecord;
use App\Entity\Article;
use App\Entity\Event;
use App\Entity\UpdateSubscription;
use App\Enum\KindsEnum;
use App\Enum\UpdateSourceTypeEnum;
use App\Service\Graph\RecordIdentityService;

/**
 * Converts existing inventory records to a shared, read-only source shape.
 * It assumes caller authorization and does not perform lookups or persistence.
 */
final readonly class EventWorkspaceSourceAdapter
{
    public function __construct(
        private RecordIdentityService $identities,
    ) {}

    public function fromOwnedCollectionEvent(Event $event): EventWorkspaceSourceRecord
    {
        return $this->fromEvent($event, EventWorkspaceSource::OwnedCollectionEvent);
    }

    public function fromBookshelfDirectory(Event $event): EventWorkspaceSourceRecord
    {
        if ($event->getKind() !== KindsEnum::DIRECTORY->value) {
            throw new \InvalidArgumentException('Bookshelf source records must be kind 30045 directories.');
        }

        return $this->fromEvent($event, EventWorkspaceSource::BookshelfDirectory);
    }

    public function fromNkbipDrive(Event $event): EventWorkspaceSourceRecord
    {
        if ($event->getKind() !== KindsEnum::DRIVE->value) {
            throw new \InvalidArgumentException('NKBIP drive source records must be kind 30042.');
        }

        return $this->fromEvent($event, EventWorkspaceSource::NkbipDrive);
    }

    public function fromAuthoredArticle(Article $article): EventWorkspaceSourceRecord
    {
        $kind = $article->getKind()?->value;
        $pubkey = $article->getPubkey();
        $eventId = $article->getEventId();
        if (!in_array($kind, [KindsEnum::LONGFORM->value, KindsEnum::LONGFORM_DRAFT->value], true)
            || !$this->isHex32($pubkey)
            || ($eventId !== null && !$this->isHex32($eventId))) {
            throw new \InvalidArgumentException('Authored source records need an article kind and valid author identity.');
        }

        $raw = $article->getRaw();
        $rawTags = is_array($raw['tags'] ?? null) ? $raw['tags'] : null;
        $identifier = $article->getSlug();
        $invalidIdentifierTag = false;
        if ($rawTags !== null) {
            $identifier = null;
            foreach ($rawTags as $tag) {
                if (!is_array($tag) || ($tag[0] ?? null) !== 'd') {
                    continue;
                }
                if (!is_string($tag[1] ?? null)) {
                    $invalidIdentifierTag = true;
                } else {
                    $identifier = $tag[1];
                }
                break;
            }
        }
        $coordinate = $invalidIdentifierTag
            ? null
            : $this->identities->deriveCoordinate($kind, $pubkey, $identifier);
        if ($coordinate === null && $eventId === null) {
            throw new \InvalidArgumentException('Authored source records need an address or exact event ID.');
        }
        $identity = $coordinate === null
            ? EventWorkspaceIdentity::eventId($eventId)
            : EventWorkspaceIdentity::address($coordinate, $eventId);

        return new EventWorkspaceSourceRecord(
            source: EventWorkspaceSource::AuthoredArticle,
            sourceRecordId: 'article:' . ($eventId ?? $coordinate),
            identity: $identity,
            ownerScope: strtolower($pubkey),
            eventId: $eventId === null ? null : strtolower($eventId),
            kind: $kind,
            authorPubkey: strtolower($pubkey),
            rawTags: $rawTags,
        );
    }

    public function fromLocalSubscription(UpdateSubscription $subscription): EventWorkspaceSourceRecord
    {
        $identity = match ($subscription->getSourceType()) {
            UpdateSourceTypeEnum::NPUB => EventWorkspaceIdentity::profile($subscription->getSourceValue()),
            UpdateSourceTypeEnum::PUBLICATION,
            UpdateSourceTypeEnum::NIP51_SET => $this->subscriptionAddress($subscription->getSourceValue()),
        };
        $sourceRecordId = $subscription->getId() === null
            ? 'subscription:' . hash(
                'sha256',
                $subscription->getUser()->getUserIdentifier() . "\0"
                . $subscription->getSourceType()->value . "\0"
                . $subscription->getSourceValue(),
            )
            : 'subscription:' . $subscription->getId();

        return new EventWorkspaceSourceRecord(
            source: EventWorkspaceSource::LocalSubscription,
            sourceRecordId: $sourceRecordId,
            identity: $identity,
            ownerScope: $subscription->getUser()->getId() === null
                ? $subscription->getUser()->getUserIdentifier()
                : $subscription->getUser()->getIdentityOwnerId(),
            eventId: null,
            kind: null,
            authorPubkey: null,
            rawTags: null,
        );
    }

    private function fromEvent(Event $event, EventWorkspaceSource $source): EventWorkspaceSourceRecord
    {
        $eventId = $event->getId();
        $pubkey = $event->getPubkey();
        if (!$this->isHex32($eventId)) {
            throw new \InvalidArgumentException('Source event IDs must be 32-byte hexadecimal values.');
        }

        $coordinate = $this->eventCoordinate($event->getKind(), $pubkey, $event->getTags());
        $identity = $coordinate === null
            ? EventWorkspaceIdentity::eventId($eventId)
            : EventWorkspaceIdentity::address($coordinate, $eventId);

        return new EventWorkspaceSourceRecord(
            source: $source,
            sourceRecordId: strtolower($eventId),
            identity: $identity,
            ownerScope: strtolower($pubkey),
            eventId: strtolower($eventId),
            kind: $event->getKind(),
            authorPubkey: $pubkey,
            rawTags: $event->getTags(),
        );
    }

    /**
     * @param array<array-key, mixed> $tags
     */
    private function eventCoordinate(int $kind, string $pubkey, array $tags): ?string
    {
        if (!$this->isHex32($pubkey)) {
            return null;
        }

        if ($this->identities->isParameterizedReplaceable($kind)) {
            $identifier = '';
            foreach ($tags as $tag) {
                if (!is_array($tag) || ($tag[0] ?? null) !== 'd') {
                    continue;
                }
                if (!is_string($tag[1] ?? null)) {
                    return null;
                }
                $identifier = $tag[1];
                break;
            }

            return $this->identities->deriveCoordinate($kind, $pubkey, $identifier);
        }

        return $this->identities->deriveReplaceableCoordinate($kind, $pubkey);
    }

    private function subscriptionAddress(string $value): EventWorkspaceIdentity
    {
        $parts = explode(':', $value, 3);
        if (count($parts) !== 3 || !ctype_digit($parts[0]) || !$this->isHex32($parts[1])) {
            throw new \InvalidArgumentException('Subscription coordinate is malformed.');
        }

        $kind = (int) $parts[0];
        $identifier = $parts[2];
        $pubkey = strtolower($parts[1]);
        if ($this->identities->isParameterizedReplaceable($kind)) {
            $coordinate = $this->identities->deriveCoordinate($kind, $pubkey, $identifier);
        } elseif ($this->identities->isReplaceable($kind) && $identifier === '') {
            $coordinate = $this->identities->deriveReplaceableCoordinate($kind, $pubkey);
        } else {
            throw new \InvalidArgumentException('Subscription coordinate kind is not addressable.');
        }

        if ($coordinate === null) {
            throw new \InvalidArgumentException('Subscription coordinate could not be canonicalized.');
        }

        return EventWorkspaceIdentity::address($coordinate);
    }

    private function isHex32(?string $value): bool
    {
        return $value !== null && strlen($value) === 64 && ctype_xdigit($value);
    }
}
