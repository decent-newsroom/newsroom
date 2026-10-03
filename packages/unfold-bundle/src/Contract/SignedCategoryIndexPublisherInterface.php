<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

use DecentNewsroom\UnfoldBundle\Config\ContentReference;

interface SignedCategoryIndexPublisherInterface
{
    /**
     * Commits only an existing, attached, owner-authored child. Replaying the
     * current signed event broadcasts it again without projecting another time.
     *
     * @return array{event_id: string, local_commit: bool, published: bool, relay_complete: bool, retryable: bool, relay_results: array<string, mixed>}
     */
    public function publish(array $signedEvent, string $publicationCoordinate, string $categoryCoordinate, string $baseEventId, ContentReference $reference, string $action): array;
}
