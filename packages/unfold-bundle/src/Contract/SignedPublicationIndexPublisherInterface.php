<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

interface SignedPublicationIndexPublisherInterface
{
    /**
     * Verify, persist/project, and publish the first signed publication index.
     *
     * Implementations must reject this operation if a current root already
     * exists for the publication coordinate.
     *
     * @param array<string, mixed> $signedEvent
     * @return array{event_id: string, published: bool, relay_results: array<string, mixed>}
     */
    public function publishRoot(array $signedEvent, string $publicationCoordinate): array;

    /**
     * Verify, persist/project, and update local About settings in one database
     * transaction, then publish the committed event to the owner's relays.
     *
     * @param array<string, mixed> $signedEvent
     * @param list<string> $relayHints
     * @return array{event_id: string, published: bool, relay_results: array<string, mixed>}
     */
    public function publish(
        array $signedEvent,
        string $publicationCoordinate,
        string $baseEventId,
        ?string $aboutCoordinate,
        array $relayHints,
        bool $updateAboutArticle = true,
    ): array;
}