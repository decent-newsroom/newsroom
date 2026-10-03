<?php

declare(strict_types=1);

namespace App\Tests\Unfold;

use App\Kernel;
use App\Unfold\InteractionOutboxStore;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class InteractionOutboxDatabaseTest extends TestCase
{
    private ?Kernel $kernel = null;
    private ?Connection $connection = null;

    protected function setUp(): void
    {
        if (getenv('RUN_UNFOLD_OUTBOX_DB_TESTS') !== '1') {
            self::markTestSkipped('Opt-in PostgreSQL check; all inserted rows are rolled back.');
        }
        $this->kernel = new Kernel('dev', false);
        $this->kernel->boot();
        $this->connection = $this->kernel->getContainer()->get('doctrine')->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        while ($this->connection?->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->kernel?->shutdown();
    }

    public function testRecoveryLeaseTransferAndFencedResultPersistInPostgres(): void
    {
        $store = new InteractionOutboxStore($this->connection);
        $id = bin2hex(random_bytes(32));
        $reader = str_repeat('c', 64);
        $row = $store->insertQueued($id, $reader, '30040:' . $reader . ':test-root',
            '30023:' . $reader . ':test-leaf', 7, 'like',
            ['id' => $id, 'pubkey' => $reader, 'kind' => 7, 'created_at' => time(), 'tags' => [], 'content' => '+', 'sig' => str_repeat('f', 128)],
            ['wss://relay.invalid']);
        self::assertSame('queued', $row['status']);
        $reservation = (new \DateTimeImmutable('+120 seconds'))->format('Y-m-d H:i:s');
        $this->connection->executeStatement('UPDATE reader_interaction_outbox SET leased_until = :lease WHERE event_id = :id',
            ['lease' => $reservation, 'id' => $id]);
        self::assertNull($store->claimForDelivery($id));
        $claim = $store->claimForDelivery($id, dispatchLease: $reservation);
        self::assertNotNull($claim);
        self::assertNull($store->claimForDelivery($id, dispatchLease: $reservation));
        $store->recordAttempt($id, 'published', ['wss://relay.invalid' => ['ok' => true]], 1, null, null, $claim['leased_until']);
        self::assertSame('published', $store->findByEventId($id)['status']);
        self::assertNull($store->findByEventId($id)['leased_until']);
        self::assertSame($id, $store->findOwned($id, $reader, '30023:' . $reader . ':test-leaf', '30040:' . $reader . ':test-root')['event_id']);
        self::assertNull($store->findOwned($id, str_repeat('a', 64), '30023:' . $reader . ':test-leaf', '30040:' . $reader . ':test-root'));
    }
}
