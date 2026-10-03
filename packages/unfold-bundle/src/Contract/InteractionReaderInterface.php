<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

interface InteractionReaderInterface
{
    /** Local-only resolution, including current signed publication membership. */
    public function target(string $publicationCoordinate, string $coordinate): ?InteractionTarget;

    public function thread(InteractionTarget $target, ?string $cursor = null): InteractionPage;

    public function parent(InteractionTarget $target, string $eventId): ?Comment;

    public function state(InteractionTarget $target, ?string $readerPubkey = null): InteractionState;

    /** Queue a bounded, deduplicated refresh; never fetch relays inline. */
    public function refresh(InteractionTarget $target): void;
}
