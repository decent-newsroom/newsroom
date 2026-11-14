<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\GraphAuditCommand;
use App\Command\GraphBackfillReferencesCommand;
use App\Command\GraphRebuildRecordCommand;
use App\Service\Graph\CurrentVersionResolver;
use App\Service\Graph\GraphLookupService;
use App\Service\Graph\RecordIdentityService;
use App\Service\Graph\ReferenceParserService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;

final class GraphProfileReferencesCommandTest extends TestCase
{
    public function testAuditSamplesProfileOnlyEventsAndRepairsAllFields(): void
    {
        $connection = $this->createMock(Connection::class);
        $parser = new ReferenceParserService(new RecordIdentityService());
        $pubkey = str_repeat('ab', 32);
        $tags = [['p', $pubkey, 'wss://relay.example', 'name']];
        $connection->expects($this->exactly(2))->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql) use ($tags): array {
                if (str_contains($sql, 'FROM event')) {
                    $this->assertStringContainsString('tags @> \'[["p"]]\'', $sql);
                    return [['id' => 'source', 'kind' => 3, 'tags' => $tags]];
                }
                return [];
            });
        $writes = [];
        $connection->expects($this->exactly(2))->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$writes): int {
                $writes[] = [$sql, $params];
                return 1;
            });
        $command = new GraphAuditCommand(
            $connection,
            $this->createMock(CurrentVersionResolver::class),
            $parser,
            $this->createMock(LoggerInterface::class),
        );
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $issues = (new \ReflectionMethod($command, 'auditParsedReferences'))->invoke($command, $io, 10);
        $this->assertCount(1, $issues);
        $this->assertSame(1, (new \ReflectionMethod($command, 'repairReferences'))->invoke($command, $issues, $io));
        [$sql, $params] = $writes[1];
        $this->assertStringContainsString('target_event_id, relation, marker, relay_hint, position, is_structural, is_resolvable, auxiliary_data', $sql);
        $this->assertSame('profile', $params['trt0']);
        $this->assertSame($pubkey, $params['tp0']);
        $this->assertSame('follows', $params['rel0']);
        $this->assertSame('wss://relay.example', $params['rh0']);
        $this->assertSame(['wss://relay.example', 'name'], json_decode($params['aux0'], true));
        $this->assertFalse($params['is0']);
    }

    public function testBackfillSelectsAndInsertsProfileOnlyEvents(): void
    {
        $connection = $this->createMock(Connection::class);
        $pubkey = str_repeat('ab', 32);
        $connection->expects($this->once())->method('fetchOne')
            ->willReturnCallback(function (string $sql): int {
                $this->assertStringContainsString('tags @> \'[["p"]]\'', $sql);
                return 1;
            });
        $connection->expects($this->once())->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql) use ($pubkey): array {
                $this->assertStringContainsString('tags @> \'[["p"]]\'', $sql);
                return [['id' => 'pack', 'kind' => 39089, 'tags' => [['p', $pubkey, '', 'tag-data']]]];
            });
        $writes = [];
        $connection->expects($this->exactly(2))->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$writes): int {
                $writes[] = [$sql, $params];
                return 1;
            });
        $command = new GraphBackfillReferencesCommand($connection, new ReferenceParserService(new RecordIdentityService()), $this->createMock(LoggerInterface::class));
        $this->assertSame(0, (new CommandTester($command))->execute([]));
        $this->assertStringContainsString('auxiliary_data', $writes[1][0]);
        $this->assertSame('member', $writes[1][1]['rel0']);
        $this->assertSame($pubkey, $writes[1][1]['tp0']);
        $this->assertSame(['', 'tag-data'], json_decode($writes[1][1]['aux0'], true));
    }

    public function testRebuildStoresProfileData(): void
    {
        $connection = $this->createMock(Connection::class);
        $pubkey = str_repeat('ab', 32);
        $connection->method('fetchAssociative')->willReturn([
            'id' => 'pack', 'kind' => 39089, 'tags' => [['p', $pubkey, 'wss://relay.example', 'role']],
        ]);
        $writes = [];
        $connection->expects($this->exactly(2))->method('executeStatement')
            ->willReturnCallback(static function (string $sql, array $params = []) use (&$writes): int {
                $writes[] = [$sql, $params];
                return 1;
            });
        $identity = new RecordIdentityService();
        $command = new GraphRebuildRecordCommand(
            $connection,
            $this->createMock(CurrentVersionResolver::class),
            new ReferenceParserService($identity),
            $identity,
            $this->createMock(GraphLookupService::class),
            $this->createMock(LoggerInterface::class),
        );
        (new \ReflectionMethod($command, 'rebuildReferences'))->invoke($command, 'pack');
        $this->assertStringContainsString('auxiliary_data', $writes[1][0]);
        $this->assertSame($pubkey, $writes[1][1][4]);
        $this->assertSame('member', $writes[1][1][8]);
        $this->assertSame(['wss://relay.example', 'role'], json_decode($writes[1][1][14], true));
    }

    public function testBulkInsertFallbacksKeepAllProfileFields(): void
    {
        foreach (['audit', 'backfill'] as $path) {
            $connection = $this->createMock(Connection::class);
            $rows = [];
            $connection->expects($this->exactly(2))->method('executeStatement')
                ->willReturnCallback(static function (string $sql, array $params = []) use (&$rows): int {
                    if (str_contains($sql, ':sei0')) {
                        throw new \RuntimeException('bulk failed');
                    }
                    $rows[] = [$sql, $params];
                    return 1;
                });
            $parser = new ReferenceParserService(new RecordIdentityService());
            $logger = $this->createMock(LoggerInterface::class);
            $command = $path === 'audit'
                ? new GraphAuditCommand($connection, $this->createMock(CurrentVersionResolver::class), $parser, $logger)
                : new GraphBackfillReferencesCommand($connection, $parser, $logger);
            $refs = $parser->parseFromTagsArray('source', 3, [['p', str_repeat('ab', 32), 'wss://relay.example', 'name']]);
            (new \ReflectionMethod($command, 'bulkInsertReferences'))->invoke($command, $refs);
            $this->assertStringContainsString('auxiliary_data', $rows[0][0]);
            $this->assertCount(15, $rows[0][1]);
            $this->assertSame('follows', $rows[0][1][8]);
            $this->assertSame(['wss://relay.example', 'name'], json_decode($rows[0][1][14], true));
        }
    }

    public function testBulkInsertChunksReferenceRowsBelowDatabaseParameterLimit(): void
    {
        $pubkey = str_repeat('ab', 32);
        $refs = (new ReferenceParserService(new RecordIdentityService()))->parseFromTagsArray(
            'source',
            3,
            array_fill(0, 4847, ['p', $pubkey]),
        );

        foreach (['audit', 'backfill'] as $path) {
            $connection = $this->createMock(Connection::class);
            $chunkParameterCounts = [];
            $connection->expects($this->exactly(2))
                ->method('executeStatement')
                ->willReturnCallback(static function (string $sql, array $params = []) use (&$chunkParameterCounts): int {
                    $chunkParameterCounts[] = count($params);

                    return 1;
                });
            $parser = new ReferenceParserService(new RecordIdentityService());
            $logger = $this->createMock(LoggerInterface::class);
            $command = $path === 'audit'
                ? new GraphAuditCommand($connection, $this->createMock(CurrentVersionResolver::class), $parser, $logger)
                : new GraphBackfillReferencesCommand($connection, $parser, $logger);

            (new \ReflectionMethod($command, 'bulkInsertReferences'))->invoke($command, $refs);

            $this->assertSame([60000, 12705], $chunkParameterCounts);
        }
    }
}
