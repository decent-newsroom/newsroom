<?php

declare(strict_types=1);

namespace App\Tests\Unit\Unfold;

use App\Repository\DeletedEventRepository;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrEventVerifier;
use App\Service\Nostr\NostrSigner;
use App\Service\Nostr\UserRelayListService;
use App\Unfold\InteractionOutboxStore;
use App\Unfold\SignedInteractionPublisher;
use DecentNewsroom\UnfoldBundle\Content\PostData;
use DecentNewsroom\UnfoldBundle\Contract\InteractionTarget;
use DecentNewsroom\UnfoldBundle\Interactions\InteractionPolicy;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\Persistence\ManagerRegistry;
use Innis\Nostr\Core\Domain\Entity\Event as NostrCoreEvent;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Infrastructure\Adapter\Secp256k1SignatureAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class SignedInteractionPublisherTest extends TestCase
{
    private const AUTHOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const READER = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const POST_EVENT_ID = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
    private const SIGNED_ID = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

    public function testRejectsUnsupportedKindBeforeAnyPersistence(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');

        $publisher = $this->publisher($connection, verifies: true);

        $this->expectExceptionObject(new \InvalidArgumentException('unfold_interactions.invalid'));
        $publisher->publish([...$this->signedLike(), 'kind' => 99999], $this->target(), self::READER);
    }

    public function testRejectsReaderPubkeyMismatchBeforeSignatureVerification(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');
        $signer = $this->createMock(NostrSigner::class);
        $signer->expects(self::never())->method('verify');

        $publisher = $this->publisher($connection, signer: $signer);

        $this->expectExceptionObject(new \InvalidArgumentException('unfold_interactions.signer_mismatch'));
        $publisher->publish([...$this->signedLike(), 'pubkey' => self::AUTHOR], $this->target(), self::READER);
    }

    public function testRejectsInvalidCryptographicSignatureEvenWhenTagsAreWellFormed(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');

        $publisher = $this->publisher($connection, verifies: false);

        $this->expectExceptionObject(new \InvalidArgumentException('unfold_interactions.invalid_signature'));
        $publisher->publish($this->signedLike(), $this->target(), self::READER);
    }

    public function testAcceptsGenuineRealCryptographicSignatureNotJustAMockedVerifier(): void
    {
        // Uses the real secp256k1 adapter end-to-end (no mocked ->verify()),
        // proving the publisher actually binds the signed payload to its own
        // hash and schnorr signature rather than trusting a stubbed boolean.
        [$signer, $readerPubkey, $event, $target] = $this->genuinelySignedLike();
        $connection = $this->scriptedConnection(existing: false);

        $delivery = $this->publisher($connection, signer: $signer)->publish($event, $target, $readerPubkey);

        self::assertSame('queued', $delivery->status);
    }

    public function testRejectsGenuinelySignedEventWhoseSignatureBytesWereTamperedAfterSigning(): void
    {
        // content/tags/pubkey/created_at/id are untouched (protocol-field
        // match in InteractionPolicy::assertSignedIntent() still passes);
        // only the signature bytes themselves are flipped. Only genuine
        // schnorr verification — not a mock — can catch this.
        [$signer, $readerPubkey, $event, $target] = $this->genuinelySignedLike();
        $event['sig'] = $this->flipFirstHexChar($event['sig']);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');

        $this->expectExceptionObject(new \InvalidArgumentException('unfold_interactions.invalid_signature'));
        $this->publisher($connection, signer: $signer)->publish($event, $target, $readerPubkey);
    }

    public function testRejectsGenuinelySignedEventWhoseIdNoLongerMatchesItsOwnContentHash(): void
    {
        // Source binding: the genuine signature is kept, but a different,
        // still well-formed 64-hex id is substituted. The real hash
        // recomputation (id must equal sha256 of pubkey/created_at/kind/
        // tags/content) must reject this independently of the signature
        // check, proving the id is bound to the actual signed source.
        [$signer, $readerPubkey, $event, $target] = $this->genuinelySignedLike();
        $event['id'] = $this->flipFirstHexChar($event['id']);
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');

        $this->expectExceptionObject(new \InvalidArgumentException('unfold_interactions.invalid_signature'));
        $this->publisher($connection, signer: $signer)->publish($event, $target, $readerPubkey);
    }

    public function testPublishWrapsDatabaseFailureAsRuntimeExceptionForExplicit503WithoutLeakingDetails(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willThrowException($this->dbalFailure('connection to server lost'));

        $publisher = $this->publisher($connection);

        try {
            $publisher->publish($this->signedLike(), $this->target(), self::READER);
            self::fail('Expected a RuntimeException to be thrown');
        } catch (\RuntimeException $e) {
            // Never the raw Doctrine/DBAL exception, and never its message
            // (which may contain DSNs/table/column details) reaching the
            // reader-facing boundary unwrapped.
            self::assertNotInstanceOf(DBALException::class, $e);
            self::assertStringNotContainsString('connection to server lost', $e->getMessage());
        }
    }

    public function testSuccessfulPublishProjectsAndQueuesAtomicallyAndNeverReturnsPublished(): void
    {
        $connection = $this->scriptedConnection(existing: false);
        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::once())->method('projectEventFromNostrEvent');
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn ($m) => $m->getEventId() === self::SIGNED_ID))
            ->willReturn(new Envelope(new \stdClass()));

        $delivery = $this->publisher($connection, projector: $projector, messageBus: $messageBus)
            ->publish($this->signedLike(), $this->target(), self::READER);

        self::assertSame(self::SIGNED_ID, $delivery->eventId);
        self::assertSame('queued', $delivery->status);
        self::assertTrue($delivery->localCommit);
        self::assertFalse($delivery->toArray()['published']);
    }

    public function testReplayOfSameEventIdNeverReprojectsOrDuplicatesOrRedispatches(): void
    {
        $connection = $this->scriptedConnection(existing: true);
        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::never())->method('projectEventFromNostrEvent');
        $connection->expects(self::never())->method('executeStatement');
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $delivery = $this->publisher($connection, projector: $projector, messageBus: $messageBus)
            ->publish($this->signedLike(), $this->target(), self::READER);

        self::assertSame('queued', $delivery->status);
    }

    public function testDispatchFailureAfterLocalCommitDoesNotLoseTheActionOrThrow(): void
    {
        $connection = $this->scriptedConnection(existing: false);
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->method('dispatch')->willThrowException(new \RuntimeException('transport down'));

        $delivery = $this->publisher($connection, messageBus: $messageBus)
            ->publish($this->signedLike(), $this->target(), self::READER);

        // Local commit already happened; the row remains durably queued for
        // the recovery command to pick up — publish() must not throw.
        self::assertSame('queued', $delivery->status);
        self::assertTrue($delivery->localCommit);
    }

    public function testStatusReturnsNullForMalformedEventIdWithoutTouchingStorage(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('fetchAssociative');

        self::assertNull($this->publisher($connection)->status('not-a-valid-id', $this->target(), self::READER));
    }

    public function testStatusRequiresOwnershipAndTargetIdentityMatch(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);

        self::assertNull($this->publisher($connection)->status(self::SIGNED_ID, $this->target(), self::READER));
    }

    /** @dataProvider identityMismatchDimensions */
    public function testStatusRejectsRowWhenActorOrTargetOrPublicationDiffersFromStoredOwnership(string $dimension): void
    {
        [$readerPubkey, $target] = $this->mismatchedIdentityFixture($dimension);
        $connection = $this->ownershipScopedConnection(InteractionOutboxStore::STATUS_QUEUED);

        self::assertNull($this->publisher($connection)->status(self::SIGNED_ID, $target, $readerPubkey));
    }

    /** @dataProvider identityMismatchDimensions */
    public function testRetryRejectsRowWhenActorOrTargetOrPublicationDiffersFromStoredOwnership(string $dimension): void
    {
        [$readerPubkey, $target] = $this->mismatchedIdentityFixture($dimension);
        $connection = $this->ownershipScopedConnection(InteractionOutboxStore::STATUS_FAILED);
        $connection->expects(self::never())->method('transactional');

        $this->expectExceptionObject(new \InvalidArgumentException('unfold_interactions.unknown'));
        $this->publisher($connection)->retry(self::SIGNED_ID, $target, $readerPubkey);
    }

    public function testStatusWrapsDatabaseFailureAsRuntimeExceptionForExplicit503(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willThrowException($this->dbalFailure('relation does not exist'));

        $this->expectException(\RuntimeException::class);
        $this->publisher($connection)->status(self::SIGNED_ID, $this->target(), self::READER);
    }

    public function testRetryRejectsStaleOrForeignTargetAndNeverResigns(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($this->rawRow(InteractionOutboxStore::STATUS_FAILED));
        $connection->expects(self::never())->method('transactional');
        $deletedEvents = $this->createMock(DeletedEventRepository::class);
        $deletedEvents->method('isSuppressed')->willReturn(false);

        $foreignTarget = $this->target('30023:' . self::AUTHOR . ':a-different-post');

        $this->expectException(\InvalidArgumentException::class);
        $this->publisher($connection, deletedEvents: $deletedEvents)->retry(self::SIGNED_ID, $foreignTarget, self::READER);
    }

    public function testRetryRejectsTombstonedTargetBeforeAnyRequeue(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($this->rawRow(InteractionOutboxStore::STATUS_FAILED));
        $connection->expects(self::never())->method('transactional');
        $deletedEvents = $this->createMock(DeletedEventRepository::class);
        $deletedEvents->method('isSuppressed')->willReturn(true);

        $this->expectExceptionObject(new \InvalidArgumentException('unfold_interactions.target_unavailable'));
        $this->publisher($connection, deletedEvents: $deletedEvents)->retry(self::SIGNED_ID, $this->target(), self::READER);
    }

    public function testTombstoneStorageFailureDoesNotProceedWithLocalAcceptance(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');
        $deletedEvents = $this->createMock(DeletedEventRepository::class);
        $deletedEvents->method('isSuppressed')->willThrowException($this->dbalFailure('Deletion storage unavailable'));
        $event = (new InteractionPolicy())->prepare($this->target(), self::READER, 'like');
        $event['id'] = self::SIGNED_ID;
        $event['sig'] = str_repeat('f', 128);
        $this->expectException(\RuntimeException::class);
        $this->publisher($connection, deletedEvents: $deletedEvents)->publish($event, $this->target(), self::READER);
    }

    public function testRetryPreservesAcceptedPayloadWhenContentRevisionAdvances(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($this->rawRow(InteractionOutboxStore::STATUS_FAILED, attempts: 3));
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $fn) => $fn());
        $connection->expects(self::once())->method('executeStatement')->with(self::stringContains('attempts = 0'));
        $oldTarget = $this->target();
        $revision = new \DecentNewsroom\UnfoldBundle\Contract\NostrEvent(
            str_repeat('f', 64), self::AUTHOR, 30023, 'Updated body', $oldTarget->post->tags, time(), str_repeat('f', 128),
        );
        $freshTarget = new InteractionTarget($oldTarget->publicationCoordinate, PostData::fromEvent($revision));
        $delivery = $this->publisher($connection)->retry(self::SIGNED_ID, $freshTarget, self::READER);
        self::assertSame(self::SIGNED_ID, $delivery->eventId);
        self::assertSame('queued', $delivery->status);
    }

    public function testRetryRequeuesFailedRowForFreshAttemptBudgetAndRedispatches(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($this->rawRow(InteractionOutboxStore::STATUS_FAILED, attempts: 3));
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $fn) => $fn());
        $connection->expects(self::once())->method('executeStatement')->with(self::stringContains('attempts = 0'));
        $deletedEvents = $this->createMock(DeletedEventRepository::class);
        $deletedEvents->method('isSuppressed')->willReturn(false);
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        $delivery = $this->publisher($connection, deletedEvents: $deletedEvents, messageBus: $messageBus)
            ->retry(self::SIGNED_ID, $this->target(), self::READER);

        self::assertSame('queued', $delivery->status);
    }

    public function testRetryOfAlreadyPublishedRowIsNoOpAndNeverRedispatches(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($this->rawRow(InteractionOutboxStore::STATUS_PUBLISHED));
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $fn) => $fn());
        $connection->expects(self::never())->method('executeStatement');
        $deletedEvents = $this->createMock(DeletedEventRepository::class);
        $deletedEvents->method('isSuppressed')->willReturn(false);
        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $delivery = $this->publisher($connection, deletedEvents: $deletedEvents, messageBus: $messageBus)
            ->retry(self::SIGNED_ID, $this->target(), self::READER);

        self::assertSame('published', $delivery->status);
    }

    public function testRetryWrapsDatabaseFailureFromRequeueAsRuntimeExceptionForExplicit503(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($this->rawRow(InteractionOutboxStore::STATUS_FAILED));
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $fn) => $fn());
        $connection->method('executeStatement')->willThrowException($this->dbalFailure('deadlock detected'));
        $deletedEvents = $this->createMock(DeletedEventRepository::class);
        $deletedEvents->method('isSuppressed')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->publisher($connection, deletedEvents: $deletedEvents)->retry(self::SIGNED_ID, $this->target(), self::READER);
    }

    // ---- fixtures ----------------------------------------------------

    private function target(?string $coordinateOverride = null): InteractionTarget
    {
        $coordinate = $coordinateOverride ?? ('30023:' . self::AUTHOR . ':post-1');
        $post = new PostData(
            slug: 'post-1',
            title: 'Post',
            summary: '',
            content: 'Body',
            image: null,
            publishedAt: 1_700_000_000,
            pubkey: self::AUTHOR,
            coordinate: $coordinate,
            kind: 30023,
            tags: [['d', 'post-1']],
            eventId: self::POST_EVENT_ID,
        );

        return new InteractionTarget('30040:' . self::AUTHOR . ':root', $post);
    }

    private function signedLike(): array
    {
        $event = (new InteractionPolicy())->prepare($this->target(), self::READER, 'like');

        return [...$event, 'id' => self::SIGNED_ID, 'sig' => str_repeat('f', 128)];
    }

    /** @return array<string, mixed> */
    private function rawRow(string $status, int $attempts = 0): array
    {
        return [
            'event_id' => self::SIGNED_ID,
            'reader_pubkey' => self::READER,
            'publication_coordinate' => '30040:' . self::AUTHOR . ':root',
            'target_coordinate' => '30023:' . self::AUTHOR . ':post-1',
            'kind' => '7',
            'action' => 'like',
            'signed_event' => json_encode($this->signedLike()),
            'relays' => json_encode(['wss://relay.example']),
            'status' => $status,
            'relay_results' => json_encode([]),
            'attempts' => (string) $attempts,
            'max_attempts' => '3',
            'next_attempt_at' => '2025-01-01 00:00:00',
            'leased_until' => null,
            'last_error' => null,
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-01 00:00:00',
        ];
    }

    private function scriptedConnection(bool $existing): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $fn) => $fn());

        if ($existing) {
            $connection->method('fetchAssociative')->willReturn($this->rawRow(InteractionOutboxStore::STATUS_QUEUED));
        } else {
            $connection->method('fetchAssociative')->willReturnOnConsecutiveCalls(false, $this->rawRow(InteractionOutboxStore::STATUS_QUEUED));
        }

        return $connection;
    }

    private function publisher(
        Connection $connection,
        bool $verifies = true,
        ?NostrSigner $signer = null,
        ?GenericEventProjector $projector = null,
        ?MessageBusInterface $messageBus = null,
        ?DeletedEventRepository $deletedEvents = null,
    ): SignedInteractionPublisher {
        if ($signer === null) {
            $signer = $this->createMock(NostrSigner::class);
            $signer->method('verify')->willReturn($verifies);
        }
        if ($deletedEvents === null) {
            $deletedEvents = $this->createMock(DeletedEventRepository::class);
            $deletedEvents->method('isSuppressed')->willReturn(false);
        }

        return new SignedInteractionPublisher(
            new NostrEventVerifier($signer),
            new InteractionPolicy(),
            $projector ?? $this->createMock(GenericEventProjector::class),
            $connection,
            new InteractionOutboxStore($connection),
            $this->relayListService(),
            $deletedEvents,
            $this->createMock(ManagerRegistry::class),
            $messageBus ?? $this->createMock(MessageBusInterface::class),
            new NullLogger(),
        );
    }

    private function relayListService(): UserRelayListService
    {
        $service = $this->createMock(UserRelayListService::class);
        $service->method('getRelaysForPublishing')->willReturn(['wss://relay.example']);
        $service->method('getFallbackRelays')->willReturn(['wss://fallback.example']);

        return $service;
    }

    /**
     * Returns a signer backed by the real secp256k1 adapter plus a genuinely
     * signed 'like' event for a freshly generated keypair, so crypto tests
     * exercise actual hash/signature verification rather than a stubbed bool.
     *
     * @return array{0: NostrSigner, 1: string, 2: array<string, mixed>, 3: InteractionTarget}
     */
    private function genuinelySignedLike(): array
    {
        $signatureService = Secp256k1SignatureAdapter::create();
        $keyPair = KeyPair::generate($signatureService);
        $readerPubkey = $keyPair->getPublicKey()->toHex();
        $target = $this->target();

        $template = (new InteractionPolicy())->prepare($target, $readerPubkey, 'like');
        $signed = NostrCoreEvent::fromArray($template)->sign($keyPair, $signatureService);

        return [new NostrSigner($signatureService), $readerPubkey, $signed->toArray(), $target];
    }

    private function flipFirstHexChar(string $hex): string
    {
        return ($hex[0] === '0' ? '1' : '0') . substr($hex, 1);
    }

    /** @return \Throwable&DBALException */
    private function dbalFailure(string $message): \Throwable
    {
        return new class($message) extends \Exception implements DBALException {
        };
    }

    /** @return array{0: string, 1: InteractionTarget} */
    private function mismatchedIdentityFixture(string $dimension): array
    {
        return match ($dimension) {
            'actor' => [str_repeat('9', 64), $this->target()],
            'target' => [self::READER, $this->target('30023:' . self::AUTHOR . ':a-different-post')],
            'publication' => [self::READER, new InteractionTarget('30040:' . self::AUTHOR . ':a-different-root', $this->target()->post)],
            default => throw new \LogicException('Unknown dimension: ' . $dimension),
        };
    }

    /** @return iterable<string, array{string}> */
    public static function identityMismatchDimensions(): iterable
    {
        yield 'different actor' => ['actor'];
        yield 'different target' => ['target'];
        yield 'different publication' => ['publication'];
    }

    /**
     * fetchAssociative only returns the canonical owned row when the bound
     * params match the canonical reader/target/publication exactly; any
     * mismatched dimension causes the ownership lookup to miss, as it would
     * against a real WHERE clause.
     */
    private function ownershipScopedConnection(string $status): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturnCallback(
            fn (string $sql, array $params) => (
                ($params['reader_pubkey'] ?? null) === self::READER
                && ($params['target_coordinate'] ?? null) === '30023:' . self::AUTHOR . ':post-1'
                && ($params['publication_coordinate'] ?? null) === '30040:' . self::AUTHOR . ':root'
            ) ? $this->rawRow($status) : false,
        );

        return $connection;
    }
}
