<?php

declare(strict_types=1);

namespace App\Tests\Unit\Unfold;

use App\Unfold\InteractionOutboxStore;
use DecentNewsroom\UnfoldBundle\Contract\InteractionDelivery;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class InteractionOutboxStoreTest extends TestCase
{
    private const EVENT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testFindByEventIdHydratesJsonColumnsAndCastsScalars(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($this->rawRow());

        $row = (new InteractionOutboxStore($connection))->findByEventId(self::EVENT_ID);

        self::assertNotNull($row);
        self::assertSame(['id' => 'abc'], $row['signed_event']);
        self::assertSame(['wss://one.example'], $row['relays']);
        self::assertSame([], $row['relay_results']);
        self::assertSame(7, $row['kind']);
        self::assertSame(0, $row['attempts']);
        self::assertSame(3, $row['max_attempts']);
        self::assertNull($row['leased_until']);
    }

    public function testFindByEventIdReturnsNullWhenMissing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);

        self::assertNull((new InteractionOutboxStore($connection))->findByEventId(self::EVENT_ID));
    }

    public function testFindByEventIdForUpdateAppendsLockingClauseOnlyWhenRequested(): void
    {
        $connection = $this->createMock(Connection::class);
        $seenSql = [];
        $connection->method('fetchAssociative')->willReturnCallback(function (string $sql) use (&$seenSql) {
            $seenSql[] = $sql;
            return $this->rawRow();
        });
        $store = new InteractionOutboxStore($connection);

        $store->findByEventId(self::EVENT_ID, false);
        $store->findByEventId(self::EVENT_ID, true);

        self::assertStringNotContainsString('FOR UPDATE', $seenSql[0]);
        self::assertStringContainsString('FOR UPDATE', $seenSql[1]);
    }

    public function testFindOwnedPassesReaderAndTargetIdentityAsBindParameters(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('fetchAssociative')->with(
            self::stringContains('reader_pubkey = :reader_pubkey'),
            [
                'event_id' => self::EVENT_ID,
                'reader_pubkey' => str_repeat('c', 64),
                'target_coordinate' => '30023:' . str_repeat('a', 64) . ':slug',
                'publication_coordinate' => '30040:' . str_repeat('a', 64) . ':root',
            ],
        )->willReturn(false);

        $row = (new InteractionOutboxStore($connection))->findOwned(
            self::EVENT_ID,
            str_repeat('c', 64),
            '30023:' . str_repeat('a', 64) . ':slug',
            '30040:' . str_repeat('a', 64) . ':root',
        );

        self::assertNull($row);
    }

    public function testInsertQueuedWritesDefaultStatusAndEmptyRelayResults(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->with(
            self::stringContains('INSERT INTO reader_interaction_outbox'),
            self::callback(function (array $params): bool {
                self::assertSame(InteractionOutboxStore::STATUS_QUEUED, $params['status']);
                self::assertSame('{}', $params['relay_results']);
                self::assertSame(3, $params['max_attempts']);
                self::assertSame(json_encode(['id' => self::EVENT_ID]), $params['signed_event']);

                return true;
            }),
        );
        $connection->method('fetchAssociative')->willReturn($this->rawRow());

        $row = (new InteractionOutboxStore($connection))->insertQueued(
            self::EVENT_ID,
            str_repeat('c', 64),
            '30040:' . str_repeat('a', 64) . ':root',
            '30023:' . str_repeat('a', 64) . ':slug',
            1111,
            'comment',
            ['id' => self::EVENT_ID],
            ['wss://one.example'],
        );

        self::assertSame(InteractionOutboxStore::STATUS_QUEUED, $row['status']);
    }

    public function testClaimDueAppliesLeaseOnlyToClaimedIdsAndSkipsWhenNoneDue(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $fn) => $fn());
        $connection->method('fetchAllAssociative')->willReturn([]);
        $connection->expects(self::never())->method('executeStatement');

        self::assertSame([], (new InteractionOutboxStore($connection))->claimDue());
    }

    public function testClaimDueClaimsEligibleRowsAndAppliesLeaseWithArrayBinding(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $fn) => $fn());
        $connection->method('fetchAllAssociative')->willReturn([
            ['event_id' => 'event-a'],
            ['event_id' => 'event-b'],
        ]);
        $connection->expects(self::once())->method('executeStatement')->with(
            self::stringContains('leased_until = :leased_until'),
            self::callback(static function (array $params): bool {
                self::assertSame(['event-a', 'event-b'], $params['ids']);
                return true;
            }),
            self::callback(static function (array $types): bool {
                self::assertSame(ArrayParameterType::STRING, $types['ids']);
                return true;
            }),
        );

        $claims = (new InteractionOutboxStore($connection))->claimDue(limit: 50, leaseSeconds: 60);

        self::assertSame(['event-a', 'event-b'], array_column($claims, 'event_id'));
        self::assertSame($claims[0]['dispatch_lease'], $claims[1]['dispatch_lease']);
    }

    public function testClaimForDeliveryReturnsNullWhenRowAlreadyResolved(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $fn) => $fn());
        $connection->method('fetchAssociative')->willReturn([...$this->rawRow(), 'status' => InteractionOutboxStore::STATUS_PUBLISHED]);
        $connection->expects(self::never())->method('executeStatement');

        self::assertNull((new InteractionOutboxStore($connection))->claimForDelivery(self::EVENT_ID));
    }

    public function testClaimForDeliveryReturnsNullWhenCurrentlyLeasedByAnotherWorker(): void
    {
        $future = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $fn) => $fn());
        $connection->method('fetchAssociative')->willReturn([...$this->rawRow(), 'leased_until' => $future]);
        $connection->expects(self::never())->method('executeStatement');

        self::assertNull((new InteractionOutboxStore($connection))->claimForDelivery(self::EVENT_ID));
    }

    public function testClaimForDeliveryClaimsEligibleRowAndSetsLease(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $fn) => $fn());
        $connection->method('fetchAssociative')->willReturn($this->rawRow());
        $connection->expects(self::once())->method('executeStatement')->with(self::stringContains('SET leased_until = :until'));

        $row = (new InteractionOutboxStore($connection))->claimForDelivery(self::EVENT_ID, 90);

        self::assertNotNull($row);
        self::assertInstanceOf(\DateTimeImmutable::class, $row['leased_until']);
    }

    public function testRecoveryReservationIsTransferredToWorkerAndCannotBeReused(): void
    {
        $reservation = (new \DateTimeImmutable('+2 minutes'))->format('Y-m-d H:i:s');
        $stored = [...$this->rawRow(), 'leased_until' => $reservation];
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $fn) => $fn());
        $connection->method('fetchAssociative')->willReturnCallback(static function () use (&$stored): array { return $stored; });
        $connection->expects(self::once())->method('executeStatement')->willReturnCallback(
            static function (string $sql, array $params) use (&$stored): int {
                $stored['leased_until'] = $params['until'];
                return 1;
            },
        );
        $store = new InteractionOutboxStore($connection);
        $claimed = $store->claimForDelivery(self::EVENT_ID, dispatchLease: $reservation);
        self::assertNotNull($claimed);
        self::assertNotSame($reservation, $claimed['leased_until']->format('Y-m-d H:i:s'));
        self::assertNull($store->claimForDelivery(self::EVENT_ID, dispatchLease: $reservation));
    }

    public function testDuplicateEnvelopeCannotBypassAutomaticBackoff(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $fn) => $fn());
        $connection->method('fetchAssociative')->willReturn([
            ...$this->rawRow(), 'next_attempt_at' => (new \DateTimeImmutable('+5 minutes'))->format('Y-m-d H:i:s'),
        ]);
        $connection->expects(self::never())->method('executeStatement');
        self::assertNull((new InteractionOutboxStore($connection))->claimForDelivery(self::EVENT_ID));
    }

    public function testExpiredWorkerCannotOverwriteAnotherWorkersResult(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')
            ->with(self::stringContains('AND leased_until = :expected_lease'), self::anything(), self::anything())
            ->willReturn(0);
        $this->expectException(\RuntimeException::class);
        (new InteractionOutboxStore($connection))->recordAttempt(
            self::EVENT_ID, 'published', [], 1, null, null, new \DateTimeImmutable(),
        );
    }

    public function testTerminalAccessFailureIsNotRetryable(): void
    {
        $delivery = InteractionOutboxStore::toDelivery([
            'event_id' => self::EVENT_ID, 'status' => 'failed', 'relay_results' => [],
            'last_error' => InteractionOutboxStore::TERMINAL_TARGET_ERROR,
        ]);
        self::assertFalse($delivery->toArray()['retryable']);
    }

    public function testRequeueForRetryIsNoOpWhenAlreadyQueuedOrPublished(): void
    {
        foreach ([InteractionOutboxStore::STATUS_QUEUED, InteractionOutboxStore::STATUS_PUBLISHED] as $status) {
            $connection = $this->createMock(Connection::class);
            $connection->method('transactional')->willReturnCallback(static fn (\Closure $fn) => $fn());
            $connection->method('fetchAssociative')->willReturn([...$this->rawRow(), 'status' => $status]);
            $connection->expects(self::never())->method('executeStatement');

            $row = (new InteractionOutboxStore($connection))->requeueForRetry(self::EVENT_ID);

            self::assertSame($status, $row['status']);
        }
    }

    public function testRequeueForRetryResetsAttemptsWhenFailedOrPartial(): void
    {
        foreach ([InteractionOutboxStore::STATUS_FAILED, InteractionOutboxStore::STATUS_PARTIAL] as $status) {
            $connection = $this->createMock(Connection::class);
            $connection->method('transactional')->willReturnCallback(static fn (\Closure $fn) => $fn());
            $connection->method('fetchAssociative')->willReturn([...$this->rawRow(), 'status' => $status, 'attempts' => 3]);
            $connection->expects(self::once())->method('executeStatement')->with(
                self::stringContains('attempts = 0'),
            );

            $row = (new InteractionOutboxStore($connection))->requeueForRetry(self::EVENT_ID);

            self::assertSame(InteractionOutboxStore::STATUS_QUEUED, $row['status']);
            self::assertSame(0, $row['attempts']);
            self::assertNull($row['last_error']);
        }
    }

    public function testRequeueForRetryReturnsNullWhenRowMissing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $fn) => $fn());
        $connection->method('fetchAssociative')->willReturn(false);

        self::assertNull((new InteractionOutboxStore($connection))->requeueForRetry(self::EVENT_ID));
    }

    public function testRecordAttemptOnlyAdvancesNextAttemptAtWhenDelayGiven(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->with(
            self::logicalAnd(self::stringContains('status = :status'), self::logicalNot(self::stringContains('next_attempt_at'))),
        );

        (new InteractionOutboxStore($connection))->recordAttempt(self::EVENT_ID, InteractionOutboxStore::STATUS_FAILED, [], 3, 'boom', null);
    }

    public function testRecordAttemptSetsNextAttemptAtWhenRetryScheduled(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')->with(
            self::stringContains('next_attempt_at = :next_attempt_at'),
            self::callback(static function (array $params): bool {
                self::assertArrayHasKey('next_attempt_at', $params);
                return true;
            }),
        );

        (new InteractionOutboxStore($connection))->recordAttempt(self::EVENT_ID, InteractionOutboxStore::STATUS_QUEUED, [], 1, null, 60);
    }

    public function testBackoffSecondsIsBoundedAtLastConfiguredValue(): void
    {
        self::assertSame(60, InteractionOutboxStore::backoffSeconds(1));
        self::assertSame(300, InteractionOutboxStore::backoffSeconds(2));
        self::assertSame(900, InteractionOutboxStore::backoffSeconds(3));
        self::assertSame(900, InteractionOutboxStore::backoffSeconds(99));
    }

    public function testToDeliveryAlwaysReportsLocalCommitTrue(): void
    {
        $delivery = InteractionOutboxStore::toDelivery([
            'event_id' => self::EVENT_ID,
            'status' => 'partial',
            'relay_results' => ['wss://one.example' => ['ok' => true, 'message' => null, 'latency_ms' => 12]],
            'last_error' => 'one relay failed',
        ]);

        self::assertInstanceOf(InteractionDelivery::class, $delivery);
        self::assertTrue($delivery->localCommit);
        self::assertSame('partial', $delivery->status);
        self::assertSame('one relay failed', $delivery->error);
        self::assertTrue($delivery->toArray()['retryable']);
    }

    /** @return array<string, mixed> */
    private function rawRow(): array
    {
        return [
            'event_id' => self::EVENT_ID,
            'reader_pubkey' => str_repeat('c', 64),
            'publication_coordinate' => '30040:' . str_repeat('a', 64) . ':root',
            'target_coordinate' => '30023:' . str_repeat('a', 64) . ':slug',
            'kind' => '7',
            'action' => 'like',
            'signed_event' => json_encode(['id' => 'abc']),
            'relays' => json_encode(['wss://one.example']),
            'status' => InteractionOutboxStore::STATUS_QUEUED,
            'relay_results' => json_encode([]),
            'attempts' => '0',
            'max_attempts' => '3',
            'next_attempt_at' => '2025-01-01 00:00:00',
            'leased_until' => null,
            'last_error' => null,
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-01 00:00:00',
        ];
    }
}
