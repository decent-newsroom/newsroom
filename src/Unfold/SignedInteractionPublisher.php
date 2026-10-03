<?php

declare(strict_types=1);

namespace App\Unfold;

use App\Enum\KindsEnum;
use App\Message\PublishReaderInteractionMessage;
use App\Repository\DeletedEventRepository;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrEventVerifier;
use App\Service\Nostr\UserRelayListService;
use App\Util\Nip22TagParser;
use DecentNewsroom\UnfoldBundle\Contract\Comment;
use DecentNewsroom\UnfoldBundle\Contract\InteractionDelivery;
use DecentNewsroom\UnfoldBundle\Contract\InteractionTarget;
use DecentNewsroom\UnfoldBundle\Interactions\InteractionPolicy;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Host implementation of SignedInteractionPublisherInterface: verified local
 * acceptance plus durable, async relay delivery of reader comments/replies,
 * likes, and reposts (NIP-22 kind 1111, NIP-25 kind 7, NIP-18 kind 16).
 *
 * Protocol tag/target-accuracy validation (and repost embedded-JSON source
 * verification) is delegated to the package's existing
 * {@see InteractionPolicy::assertSignedIntent()} — the same deterministic
 * `prepare()` template the client was asked to sign is recomputed here and
 * compared field-for-field, so a tampered or foreign payload is rejected
 * without a second, divergent protocol parser. This class adds the
 * defense-in-depth layers the policy class deliberately does not own because
 * it has no crypto/Doctrine/Redis dependencies: actual cryptographic
 * signature verification, NIP-09 tombstone checks, atomic local
 * projection + durable outbox commit, and non-network relay resolution.
 *
 * Local acceptance (signature verification, projection, outbox insert) is one
 * atomic SQL transaction. Relay broadcast never runs synchronously in the
 * request; publish() always returns a `queued` delivery. Replaying the same
 * signed event id never re-projects, never duplicates the outbox row, and
 * never mutates the stored signed payload.
 */
final readonly class SignedInteractionPublisher implements \DecentNewsroom\UnfoldBundle\Contract\SignedInteractionPublisherInterface
{
    private const SUPPORTED_KINDS = [
        KindsEnum::REACTION->value,
        KindsEnum::GENERIC_REPOST->value,
        KindsEnum::COMMENTS->value,
    ];

    private const MAX_RELAYS = 12;

    public function __construct(
        private NostrEventVerifier $verifier,
        private InteractionPolicy $policy,
        private GenericEventProjector $projector,
        private Connection $connection,
        private InteractionOutboxStore $outbox,
        private UserRelayListService $userRelayListService,
        private DeletedEventRepository $deletedEventRepository,
        private ManagerRegistry $managerRegistry,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function publish(array $signedEvent, InteractionTarget $target, string $readerPubkey): InteractionDelivery
    {
        $this->assertShape($signedEvent);
        $kind = (int) $signedEvent['kind'];
        if (!in_array($kind, self::SUPPORTED_KINDS, true)) {
            throw new \InvalidArgumentException('unfold_interactions.invalid');
        }
        if (($signedEvent['pubkey'] ?? null) !== $readerPubkey) {
            throw new \InvalidArgumentException('unfold_interactions.signer_mismatch');
        }

        [$action, $parent] = $this->resolveActionAndParent($kind, $signedEvent['tags']);

        // Protocol-level target accuracy, reader match, and (for reposts)
        // embedded original-JSON source verification. assertSignedIntent()
        // recomputes the exact expected event from $target via prepare() and
        // requires a field-for-field match, so a tampered/foreign payload
        // (including a forged repost source) is rejected here.
        $this->policy->assertSignedIntent($signedEvent, $target, $readerPubkey, $action, $parent);

        // Defense-in-depth: the policy only format-checks id/sig; verify the
        // actual cryptographic signature/hash independently.
        $event = $this->verifySignature($signedEvent);
        if ($kind === KindsEnum::GENERIC_REPOST->value && $target->original !== null) {
            $original = $target->original;
            $this->verifySignature([
                'id' => $original->id, 'pubkey' => $original->pubkey, 'kind' => $original->kind,
                'content' => $original->content, 'tags' => $original->tags,
                'created_at' => $original->createdAt, 'sig' => $original->sig,
            ]);
        }

        if ($this->isTargetTombstoned($target) || $this->isInteractionTombstoned($signedEvent)) {
            throw new \InvalidArgumentException('unfold_interactions.target_unavailable');
        }

        $eventId = (string) $signedEvent['id'];
        $publicationCoordinate = $target->publicationCoordinate;
        $targetCoordinate = $target->post->coordinate;

        $projectionAttempted = false;
        $isReplay = false;
        $row = null;

        try {
            $row = $this->connection->transactional(function () use (
                $signedEvent,
                $event,
                $eventId,
                $readerPubkey,
                $publicationCoordinate,
                $targetCoordinate,
                $kind,
                $action,
                &$projectionAttempted,
                &$isReplay,
            ): array {
                // Event-id replay: same signed event posted again (duplicate
                // POST, queue retry, relay re-ingestion). Never duplicates
                // the outbox row and never re-projects or re-resolves relays.
                $existing = $this->outbox->findByEventId($eventId, true);
                if ($existing !== null) {
                    $isReplay = true;
                    if ($existing['reader_pubkey'] !== $readerPubkey
                        || $existing['publication_coordinate'] !== $publicationCoordinate
                        || $existing['target_coordinate'] !== $targetCoordinate
                    ) {
                        // Same event id claimed under a different owner/target is
                        // not possible for a correctly-hashed Nostr event id, but
                        // never silently accept a mismatch.
                        throw new \InvalidArgumentException('unfold_interactions.conflict');
                    }

                    return $existing;
                }

                $projectionAttempted = true;
                // GenericEventProjector dedupes by event id and is NIP-09
                // tombstone-aware for the interaction event itself; same-actor
                // replays and tombstoned authors never inflate local counts.
                $this->projector->projectEventFromNostrEvent((object) $event->toArray(), 'local');

                $relays = $this->resolveRelays($readerPubkey, $targetCoordinate);

                return $this->outbox->insertQueued(
                    eventId: $eventId,
                    readerPubkey: $readerPubkey,
                    publicationCoordinate: $publicationCoordinate,
                    targetCoordinate: $targetCoordinate,
                    kind: $kind,
                    action: $action,
                    signedEvent: $signedEvent,
                    relays: $relays,
                );
            });
        } catch (\Throwable $e) {
            // Rollback restores SQL rows but not Doctrine's identity map; a
            // retry must not mistake a rolled-back entity for a stored event.
            if ($projectionAttempted) {
                $this->managerRegistry->resetManager();
            }

            throw $this->wrapDatabaseFailure($e);
        }

        if (!$isReplay) {
            // Local commit has already happened; a dispatch failure here must
            // never lose the action. The recovery command (due-record scan)
            // will pick up this row regardless of whether this dispatch lands.
            try {
                $this->messageBus->dispatch(new PublishReaderInteractionMessage($eventId));
            } catch (\Throwable $e) {
                $this->logger->warning('Reader interaction committed locally but dispatch failed; durable recovery will retry', [
                    'event_id' => $eventId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Success of the HTTP call is local_commit + queued, never "published".
        return InteractionOutboxStore::toDelivery($row);
    }

    public function status(string $eventId, InteractionTarget $target, string $readerPubkey): ?InteractionDelivery
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $eventId) !== 1) {
            return null;
        }

        try {
            $row = $this->outbox->findOwned($eventId, $readerPubkey, $target->post->coordinate, $target->publicationCoordinate);
        } catch (DBALException $e) {
            throw $this->wrapDatabaseFailure($e);
        }

        return $row === null ? null : InteractionOutboxStore::toDelivery($row);
    }

    public function retry(string $eventId, InteractionTarget $target, string $readerPubkey): InteractionDelivery
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $eventId) !== 1) {
            throw new \InvalidArgumentException('unfold_interactions.unknown');
        }

        try {
            $row = $this->outbox->findOwned($eventId, $readerPubkey, $target->post->coordinate, $target->publicationCoordinate);
        } catch (DBALException $e) {
            throw $this->wrapDatabaseFailure($e);
        }
        if ($row === null) {
            throw new \InvalidArgumentException('unfold_interactions.unknown');
        }
        if ($row['reader_pubkey'] !== $readerPubkey
            || $row['target_coordinate'] !== $target->post->coordinate
            || $row['publication_coordinate'] !== $target->publicationCoordinate) {
            throw new \InvalidArgumentException('unfold_interactions.unknown');
        }

        if ($this->isTargetTombstoned($target) || $this->isInteractionTombstoned($row['signed_event'])) {
            throw new \InvalidArgumentException('unfold_interactions.target_unavailable');
        }

        // Membership/public scope is resolved afresh by the caller and worker.
        // An accepted event still references its original revision; advancing
        // that revision must not force a second signature or invalidate retries.
        if (!$target->post->isPublic()) {
            throw new \InvalidArgumentException('unfold_interactions.target_unavailable');
        }
        $this->verifySignature($row['signed_event']);

        try {
            $updated = $this->outbox->requeueForRetry($eventId);
        } catch (DBALException $e) {
            throw $this->wrapDatabaseFailure($e);
        }
        if ($updated === null) {
            // Row disappeared between the lookup above and the requeue
            // attempt (should not happen outside of concurrent manual bans).
            throw new \InvalidArgumentException('unfold_interactions.unknown');
        }

        if ($updated['status'] === InteractionOutboxStore::STATUS_QUEUED) {
            try {
                $this->messageBus->dispatch(new PublishReaderInteractionMessage($eventId));
            } catch (\Throwable $e) {
                $this->logger->warning('Reader interaction retry requested but dispatch failed; durable recovery will retry', [
                    'event_id' => $eventId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return InteractionOutboxStore::toDelivery($updated);
    }

    /** @param array<string, mixed> $signedEvent */
    private function assertShape(array $signedEvent): void
    {
        foreach (['id', 'pubkey', 'created_at', 'kind', 'tags', 'content', 'sig'] as $field) {
            if (!array_key_exists($field, $signedEvent)) {
                throw new \InvalidArgumentException('unfold_interactions.invalid');
            }
        }
        if (!is_string($signedEvent['id']) || !is_string($signedEvent['pubkey']) || !is_string($signedEvent['sig'])
            || !is_string($signedEvent['content']) || !is_int($signedEvent['created_at']) || !is_int($signedEvent['kind'])
            || !is_array($signedEvent['tags'])
        ) {
            throw new \InvalidArgumentException('unfold_interactions.invalid');
        }
        foreach ($signedEvent['tags'] as $tag) {
            if (!is_array($tag) || !array_is_list($tag)) {
                throw new \InvalidArgumentException('unfold_interactions.invalid');
            }
        }
    }

    /**
     * @param list<list<string>> $tags
     * @return array{0: string, 1: ?Comment}
     */
    private function resolveActionAndParent(int $kind, array $tags): array
    {
        return match ($kind) {
            KindsEnum::REACTION->value => ['like', null],
            KindsEnum::GENERIC_REPOST->value => ['repost', null],
            KindsEnum::COMMENTS->value => $this->resolveCommentAction($tags),
            default => throw new \InvalidArgumentException('unfold_interactions.invalid'),
        };
    }

    /**
     * @param list<list<string>> $tags
     * @return array{0: string, 1: ?Comment}
     */
    private function resolveCommentAction(array $tags): array
    {
        $parsed = Nip22TagParser::parse($tags);
        if ($parsed['parentKind'] !== '1111') {
            return ['comment', null];
        }

        // A reply's own lowercase e/p tags already name the parent comment;
        // reconstructing it here from the signed event's own tags makes the
        // root-tag (A/K/P) comparison in assertSignedIntent() meaningful.
        // Full reply-chain/ancestry validation (foreign parents, cycles,
        // conflicting roots) is the package/controller's responsibility, not
        // this host defense-in-depth layer's.
        $parentId = $parsed['parentEventId'] ?? '';
        $parentPubkey = $parsed['parentPubkeys'][0] ?? '';

        return ['reply', new Comment($parentId, 1111, $parentPubkey, '', 0)];
    }

    /**
     * @param array<string, mixed> $signedEvent
     */
    private function verifySignature(array $signedEvent): \Innis\Nostr\Core\Domain\Entity\Event
    {
        try {
            $event = $this->verifier->fromArray($signedEvent);
            if (!$this->verifier->verify($event)) {
                throw new \InvalidArgumentException('unfold_interactions.invalid_signature');
            }

            return $event;
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\TypeError | \ValueError $e) {
            throw new \InvalidArgumentException('unfold_interactions.invalid_signature', 0, $e);
        }
    }

    /**
     * Infrastructure failures (lost DB connection, statement timeout, etc.)
     * must never leak backend details to the reader and must never be
     * mistaken for a domain rejection (`unfold_interactions.*`, mapped to
     * 422/409 by the controller). Wrapping as a plain RuntimeException routes
     * it through the controller's generic `unfold_interactions.unavailable`
     * / 503 path instead, while the real cause is still logged here for
     * operators.
     */
    private function wrapDatabaseFailure(\Throwable $e): \Throwable
    {
        if (!$e instanceof DBALException) {
            return $e;
        }

        $this->logger->error('Reader interaction outbox database operation failed', [
            'error' => $e->getMessage(),
        ]);

        return new \RuntimeException('Reader interaction storage is temporarily unavailable', 0, $e);
    }

    private function isTargetTombstoned(InteractionTarget $target): bool
    {
        $post = $target->post;
        $parts = explode(':', $post->coordinate, 3);
        $dTag = $parts[2] ?? null;

        try {
            return $this->deletedEventRepository->isSuppressed($post->eventId, $post->kind, $post->pubkey, $dTag, $target->original?->createdAt ?? $post->publishedAt);
        } catch (DBALException $e) {
            throw $this->wrapDatabaseFailure($e);
        }

    }

    private function isInteractionTombstoned(array $event): bool
    {
        try {
            return $this->deletedEventRepository->isSuppressed(
                $event['id'], $event['kind'], $event['pubkey'], null, $event['created_at'],
            );
        } catch (DBALException $e) {
            throw $this->wrapDatabaseFailure($e);
        }
    }

    /** @return list<string> */
    private function resolveRelays(string $readerPubkey, string $targetCoordinate): array
    {
        $authorPubkey = explode(':', $targetCoordinate, 3)[1] ?? null;
        $relays = [];

        foreach (array_unique(array_filter([$readerPubkey, $authorPubkey])) as $pubkey) {
            // Cache/DB-only resolution; never fetch relays in the request.
            foreach ($this->userRelayListService->getRelaysForPublishing($pubkey) as $relay) {
                if (!in_array($relay, $relays, true)) {
                    $relays[] = $relay;
                }
                if (count($relays) >= self::MAX_RELAYS) {
                    break 2;
                }
            }
        }

        if ($relays === []) {
            $relays = $this->userRelayListService->getFallbackRelays();
        }

        return array_slice(array_values(array_unique($relays)), 0, self::MAX_RELAYS);
    }
}
