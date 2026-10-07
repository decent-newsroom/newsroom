<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\BanElasticsearchAuthorsCommand;
use App\Service\Admin\AuthorBanService;
use App\Service\Admin\ElasticsearchAuthorBanList;
use App\Service\Admin\PubkeyContentPurger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class BanElasticsearchAuthorsCommandTest extends TestCase
{
    private string $export;

    protected function setUp(): void
    {
        $this->export = tempnam(sys_get_temp_dir(), 'es-author-bans-');
        file_put_contents($this->export, json_encode([
            'hits' => ['total' => ['value' => 10000, 'relation' => 'gte'], 'hits' => [
                ['_id' => '42', '_source' => ['pubkey' => str_repeat('a', 64)]],
                ['_id' => '43', '_source' => ['pubkey' => str_repeat('a', 64)]],
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        unlink($this->export);
    }

    /** @dataProvider previewProvider */
    public function testDryRunOrDeclinedConfirmationDoesNotMutate(bool $dryRun): void
    {
        $bans = $this->createMock(AuthorBanService::class);
        $bans->expects(self::never())->method('ban');
        $purger = $this->createMock(PubkeyContentPurger::class);
        $purger->method('counts')->willReturn(['article' => 200, 'event' => 300]);
        $purger->expects(self::never())->method('purge');
        $tester = new CommandTester(new BanElasticsearchAuthorsCommand(new ElasticsearchAuthorBanList(), $bans, $purger, new NullLogger()));
        $tester->setInputs(['no']);

        self::assertSame(Command::SUCCESS, $tester->execute(['file' => $this->export, '--dry-run' => $dryRun]));
        self::assertStringContainsString('1 unique authors from 2 exported hits', $tester->getDisplay());
        self::assertStringContainsString('not every search match', $tester->getDisplay());
    }

    public static function previewProvider(): array
    {
        return ['dry run' => [true], 'decline' => [false]];
    }

    public function testConfirmedImportCommitsBansBeforeAuthorWidePurge(): void
    {
        $committed = false;
        $bans = $this->createMock(AuthorBanService::class);
        $bans->expects(self::once())->method('ban')
            ->with([str_repeat('a', 64)], 'spam', 'operator')
            ->willReturnCallback(static function () use (&$committed): void {
                $committed = true;
            });
        $purger = $this->createMock(PubkeyContentPurger::class);
        $purger->method('counts')->willReturn(['article' => 200, 'event' => 300]);
        $purger->expects(self::once())->method('purge')
            ->with([str_repeat('a', 64)])
            ->willReturnCallback(static function () use (&$committed): array {
                self::assertTrue($committed);
                return ['article' => 200, 'event' => 300, 'elasticsearch' => 220];
            });
        $tester = new CommandTester(new BanElasticsearchAuthorsCommand(new ElasticsearchAuthorBanList(), $bans, $purger, new NullLogger()));

        self::assertSame(Command::SUCCESS, $tester->execute([
            'file' => $this->export, '--confirm' => true, '--reason' => 'spam', '--added-by' => 'operator',
        ]));
        self::assertStringContainsString('220 Elasticsearch documents', $tester->getDisplay());
        self::assertStringContainsString('500 local rows', $tester->getDisplay());
    }

    public function testPurgeFailureReportsFailureAndExplainsRetainedBans(): void
    {
        $bans = $this->createMock(AuthorBanService::class);
        $bans->expects(self::once())->method('ban');
        $purger = $this->createMock(PubkeyContentPurger::class);
        $purger->method('counts')->willReturn(['article' => 200]);
        $purger->method('purge')->willThrowException(new \RuntimeException('Elasticsearch unavailable'));
        $tester = new CommandTester(new BanElasticsearchAuthorsCommand(new ElasticsearchAuthorBanList(), $bans, $purger, new NullLogger()));

        self::assertSame(Command::FAILURE, $tester->execute(['file' => $this->export, '--confirm' => true]));
        self::assertStringContainsString('Elasticsearch unavailable', $tester->getDisplay());
        self::assertStringContainsString('they remain active', $tester->getDisplay());
    }

    public function testInvalidExportMakesNoDatabaseOrSearchCalls(): void
    {
        file_put_contents($this->export, '{"hits":{"hits":[{"_id":"42"}]}}');
        $bans = $this->createMock(AuthorBanService::class);
        $bans->expects(self::never())->method('ban');
        $purger = $this->createMock(PubkeyContentPurger::class);
        $purger->expects(self::never())->method('counts');
        $purger->expects(self::never())->method('purge');
        $tester = new CommandTester(new BanElasticsearchAuthorsCommand(new ElasticsearchAuthorBanList(), $bans, $purger, new NullLogger()));

        self::assertSame(Command::FAILURE, $tester->execute(['file' => $this->export, '--confirm' => true]));
    }
}
