<?php

declare(strict_types=1);

namespace App\Dto\EventWorkspace;

/**
 * One occurrence in the source tags, not an authorized or resolved target.
 *
 * `position` is the zero-based source-tag position, not the reference number.
 * `target` is a canonical graph coordinate, exact event ID, or profile pubkey
 * according to `type`. `rawValue` and the presentation's raw tags retain the
 * original spelling. Relay hints are untrusted and must be validated before use.
 */
final readonly class EventReference
{
    public function __construct(
        public string $sourceEventId,
        public int $position,
        public string $tagName,
        public EventReferenceType $type,
        public ?string $rawValue,
        public ?string $target,
        public ?int $targetKind,
        public ?string $targetPubkey,
        public ?string $targetIdentifier,
        public EventReferenceRole $role,
        public EventReferenceStatus $status,
        public ?string $relayHint,
        public ?string $versionEventId,
    ) {}
}
