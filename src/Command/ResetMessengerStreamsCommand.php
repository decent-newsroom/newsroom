<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:messenger:reset-streams',
    description: 'Reset Redis messenger consumer groups to skip stale messages',
)]
class ResetMessengerStreamsCommand extends Command
{
    public function __construct(
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be reset without making changes')
            ->addOption('cleanup', null, InputOption::VALUE_NONE, 'Delete consumer groups from other environments (e.g. stale prod groups on dev streams)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $cleanup = $input->getOption('cleanup');

        $streams = [
            "{$this->environment}:messenger:async"     => $this->environment,
            "{$this->environment}:messenger:async_low"  => "{$this->environment}-low",
        ];

        $dsn = $_ENV['MESSENGER_TRANSPORT_DSN'] ?? $_SERVER['MESSENGER_TRANSPORT_DSN'] ?? null;
        if (!$dsn) {
            $io->error('MESSENGER_TRANSPORT_DSN not set');
            return Command::FAILURE;
        }

        // Parse Redis connection from DSN
        $parsed = parse_url($dsn);
        $host = $parsed['host'] ?? '127.0.0.1';
        $port = (int) ($parsed['port'] ?? 6379);
        $pass = isset($parsed['pass']) ? urldecode($parsed['pass']) : ($parsed['user'] ?? null);
        // Handle redis://:password@host format
        if (!$pass && isset($parsed['user'])) {
            $pass = urldecode($parsed['user']);
        }

        try {
            $redis = new \Redis();
            $redis->connect($host, $port);
            if ($pass) {
                $redis->auth($pass);
            }
        } catch (\Throwable $e) {
            $io->error('Cannot connect to Redis: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->title("Messenger Stream Reset (env: {$this->environment})");

        foreach ($streams as $stream => $group) {
            // Check current state
            try {
                $groups = $redis->xInfo('GROUPS', $stream);
            } catch (\Throwable) {
                $io->warning("Stream «{$stream}» does not exist — skipping");
                continue;
            }

            if (!is_array($groups) || empty($groups)) {
                $io->warning("No consumer groups on stream «{$stream}» — skipping");
                continue;
            }

            $found = false;
            foreach ($groups as $g) {
                $name = $g['name'] ?? $g[1] ?? null;

                // Clean up groups from other environments
                if ($cleanup && $name !== $group) {
                    if ($dryRun) {
                        $io->note("Dry run — would delete stale group «{$name}» from stream «{$stream}»");
                    } else {
                        $redis->rawCommand('XGROUP', 'DESTROY', $stream, $name);
                        $io->success("Deleted stale group «{$name}» from stream «{$stream}»");
                    }
                    continue;
                }

                if ($name !== $group) {
                    continue;
                }
                $found = true;
                $lag = $g['lag'] ?? $g[11] ?? '?';
                $lastId = $g['last-delivered-id'] ?? $g[7] ?? '?';

                $io->section($stream);
                $io->text("Group: <info>{$group}</info>  |  Lag: <comment>{$lag}</comment>  |  Last delivered: {$lastId}");

                if ($dryRun) {
                    $io->note("Dry run — would reset group «{$group}» to latest (+)");
                } else {
                    $redis->rawCommand('XGROUP', 'SETID', $stream, $group, '+');
                    $io->success("Reset «{$group}» → all stale messages skipped");
                }
            }

            if (!$found) {
                $io->warning("Consumer group «{$group}» not found on stream «{$stream}»");
            }
        }

        if (!$dryRun) {
            $io->newLine();
            $io->info('Done. Restart the worker to pick up only new messages.');
        }

        return Command::SUCCESS;
    }
}


