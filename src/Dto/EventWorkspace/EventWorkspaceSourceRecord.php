<?php

declare(strict_types=1);

namespace App\Dto\EventWorkspace;

/**
 * Typed inventory input that preserves source provenance and signed tag order.
 * Local subscriptions intentionally carry no fabricated event fields.
 */
final readonly class EventWorkspaceSourceRecord
{
    /** @var array<array-key, mixed>|null */
    private ?array $rawTags;

    /**
     * @param array<array-key, mixed>|null $rawTags Null means the projection did
     *        not carry signed tags; an empty list means the event has no tags.
     */
    public function __construct(
        public EventWorkspaceSource $source,
        public string $sourceRecordId,
        public EventWorkspaceIdentity $identity,
        public string $ownerScope,
        public ?string $eventId,
        public ?int $kind,
        public ?string $authorPubkey,
        ?array $rawTags,
    ) {
        if ($sourceRecordId === '' || $ownerScope === '') {
            throw new \InvalidArgumentException('A source record needs stable source and owner scope identifiers.');
        }

        if ($source === EventWorkspaceSource::LocalSubscription
            && ($eventId !== null || $kind !== null || $authorPubkey !== null || $rawTags !== null)) {
            throw new \InvalidArgumentException('Local subscriptions must not be represented as signed events.');
        }

        if ($source !== EventWorkspaceSource::LocalSubscription) {
            if ($kind === null || $authorPubkey === null) {
                throw new \InvalidArgumentException('Event-backed source records must retain kind and author identity.');
            }
            if ($source !== EventWorkspaceSource::AuthoredArticle && $rawTags === null) {
                throw new \InvalidArgumentException('Signed event source records must retain their raw tags.');
            }
            if ($eventId === null
                && ($source !== EventWorkspaceSource::AuthoredArticle
                    || $identity->type !== EventWorkspaceIdentityType::Address
                    || $identity->versionEventId !== null)) {
                throw new \InvalidArgumentException('Event-backed source records must retain an exact event identity.');
            }
        }

        if ($eventId !== null && (strlen($eventId) !== 64 || !ctype_xdigit($eventId))) {
            throw new \InvalidArgumentException('Source event IDs must be 32-byte hexadecimal values.');
        }

        if ($eventId !== null && $identity->type === EventWorkspaceIdentityType::Address
            && $identity->versionEventId !== strtolower($eventId)) {
            throw new \InvalidArgumentException('Address-backed source records must pin their exact event version.');
        }

        if ($eventId !== null && $identity->type === EventWorkspaceIdentityType::EventId
            && $identity->value !== strtolower($eventId)) {
            throw new \InvalidArgumentException('Exact event identities must match the source event ID.');
        }

        $this->rawTags = $rawTags === null ? null : self::copyJsonValue($rawTags);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function getRawTags(): ?array
    {
        return $this->rawTags === null ? null : self::copyJsonValue($this->rawTags);
    }

    private static function copyJsonValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $item) {
                $copy[$key] = self::copyJsonValue($item);
            }

            return $copy;
        }

        if ($value instanceof \stdClass) {
            $copy = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                $copy->{$key} = self::copyJsonValue($item);
            }

            return $copy;
        }

        if (is_object($value) || is_resource($value)) {
            throw new \InvalidArgumentException('Source tags must contain decoded JSON values.');
        }

        return $value;
    }
}
