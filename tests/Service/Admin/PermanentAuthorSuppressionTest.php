<?php

declare(strict_types=1);

namespace App\Tests\Service\Admin;

use App\Service\Admin\AuthorContentCacheInvalidator;
use App\Service\Admin\PubkeyContentPurger;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Tools\DsnParser;
use Elastica\Index;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PermanentAuthorSuppressionTest extends TestCase
{
    public function testMigrationPreventsReingestionAfterPurgingAllOriginalRows(): void
    {
        require_once dirname(__DIR__, 3) . '/migrations/Version20261007190000.php';
        $url = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? null;
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('DATABASE_URL is required for PostgreSQL suppression integration tests.');
        }
        $parameters = (new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($url);
        $connection = DriverManager::getConnection($parameters);
        $connection->beginTransaction();
        try {
            // An isolated, transaction-scoped schema avoids touching live tables or workers.
            $schemaName = 'author_ban_test_' . bin2hex(random_bytes(6));
            $connection->executeStatement('CREATE SCHEMA ' . $schemaName);
            $connection->executeStatement('SET LOCAL search_path TO ' . $schemaName);
            $tables = ['article', 'event', 'highlight', 'magazine', 'current_record'];
            foreach ($tables as $table) {
                $connection->executeStatement("CREATE TABLE $table (id VARCHAR(64), event_id VARCHAR(64), current_event_id VARCHAR(64), pubkey VARCHAR(64) NOT NULL, content TEXT DEFAULT '')");
            }
            $connection->executeStatement('CREATE TABLE parsed_reference (source_event_id VARCHAR(64))');
            $migration = new \DoctrineMigrations\Version20261007190000($connection, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $connection->executeStatement($query->getStatement());
            }

            $banned = str_repeat('a', 64);
            $allowed = str_repeat('b', 64);
            foreach ($tables as $table) {
                foreach ([$banned, $allowed] as $pubkey) {
                    $connection->executeStatement(
                        "INSERT INTO $table (pubkey, id, event_id, current_event_id) VALUES (?, ?, ?, ?)",
                        [$pubkey, $pubkey, $pubkey, $pubkey],
                    );
                }
            }
            foreach ([$banned, $allowed] as $source) {
                $connection->executeStatement('INSERT INTO parsed_reference (source_event_id) VALUES (?)', [$source]);
            }
            $connection->executeStatement(
                "INSERT INTO banned_pubkey (pubkey, reason, added_at, added_by) VALUES (?, 'spam', CURRENT_TIMESTAMP, 'integration-test')",
                [$banned],
            );
            $index = $this->createMock(Index::class);
            $index->expects(self::never())->method('deleteByQuery');
            $cache = $this->createMock(AuthorContentCacheInvalidator::class);
            $cache->expects(self::exactly(2))->method('invalidate');
            $purger = new PubkeyContentPurger($connection, $index, false, $cache, new NullLogger());
            self::assertSame(1, $purger->counts([$banned])['article']);
            $deleted = $purger->purge([$banned]);
            foreach ([...$tables, 'parsed_reference'] as $table) {
                self::assertSame(1, $deleted[$table]);
                self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM $table"));
            }

            foreach ($tables as $table) {
                $connection->executeStatement("INSERT INTO $table (pubkey) VALUES (?)", [$allowed]);
                foreach ([$banned, strtoupper($banned)] as $pubkey) {
                    $connection->createSavepoint('rejected_author');
                    try {
                        $connection->executeStatement("INSERT INTO $table (pubkey) VALUES (?)", [$pubkey]);
                        self::fail('A banned author was reingested into ' . $table);
                    } catch (DriverException $e) {
                        self::assertSame('23514', $e->getSQLState());
                    } finally {
                        $connection->rollbackSavepoint('rejected_author');
                        $connection->releaseSavepoint('rejected_author');
                    }
                }
                $connection->createSavepoint('rejected_reassignment');
                try {
                    $connection->executeStatement("UPDATE $table SET pubkey = ?", [$banned]);
                    self::fail('A stored row was reassigned to a banned author in ' . $table);
                } catch (DriverException $e) {
                    self::assertSame('23514', $e->getSQLState());
                } finally {
                    $connection->rollbackSavepoint('rejected_reassignment');
                    $connection->releaseSavepoint('rejected_reassignment');
                }
                self::assertSame(2, (int) $connection->fetchOne("SELECT COUNT(*) FROM $table"));
            }
            self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM banned_pubkey'));
        } finally {
            $connection->rollBack();
            $connection->close();
        }
    }
}
