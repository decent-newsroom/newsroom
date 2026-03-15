<?php

declare(strict_types=1);

namespace App\Service\Graph;

/**
 * Immutable DTO representing a single parsed reference from an event's tags.
 * Ready for insertion into the parsed_reference table.
 */
final readonly class ParsedReferenceDto
{
    public function __construct(
        public string $sourceEventId,
        public string $tagName,
        public string $targetRefType,
        public ?int $targetKind,
        public ?string $targetPubkey,
        public ?string $targetDTag,
        public ?string $targetCoord,
        public string $relation,
        public ?string $marker,
        public int $position,
        public bool $isStructural,
        public bool $isResolvable,
    ) {}
}

