<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Inspect / repair Symfony Messenger Redis Streams consumer groups.
 *
 * Default mode (no flags): read-only diagnostic — lists every stream, its
 * consumer group(s), per-consumer pending counts, and the number of
 * stream-level pending entries. Nothing is modified.
 *
 * --reclaim : safe repair. Runs XAUTOCLAIM on every pending entry older than
 *             the given idle threshold (default 60s) and reassigns it to a
 *             freshly-named consumer so a live worker will pick it up and ack
 *             it. No messages are lost. Use this after scaling workers or
 *             after a bad consumer name change leaves orphaned pendings.
 *
 * --cleanup : delete consumer groups that don't belong to the current
 *             environment (e.g. stale `dev` groups left on a stream).
 *
 * --discard : DESTRUCTIVE. Moves last-delivered-id to the end of the stream
 *             (`XGROUP SETID … +`), permanently skipping every undelivered
 *             message. Only use when you deliberately want to wipe a backlog.
 *
 * --dry-run : preview what would happen without making changes.
 */
#[AsCommand(
    name: 'app:messenger:reset-streams',
    description: 'Inspect or repair Redis messenger consumer groups (safe by default)',
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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would happen without making changes')
            ->addOption('reclaim', null, InputOption::VALUE_NONE, 'Safely reclaim stuck pending entries to a live consumer via XAUTOCLAIM (non-destructive)')
            ->addOption('min-idle', null, InputOption::VALUE_REQUIRED, 'Minimum idle time in milliseconds for --reclaim', '60000')
            ->addOption('cleanup', null, InputOption::VALUE_NONE, 'Delete consumer groups from other environments')
            ->addOption('discard', null, InputOption::VALUE_NONE, 'DESTRUCTIVE: skip all undelivered messages (XGROUP SETID ... +)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun  = (bool) $input->getOption('dry-run');
        $reclaim = (bool) $input->getOption('reclaim');
        $cleanup = (bool) $input->getOption('cleanup');
        $discard = (bool) $input->getOption('discard');
        $minIdle = (int) $input->getOption('min-idle');

        // Every messenger transport defined in config/packages/messenger.yaml.
        // Keep this in sync with that file.
        $streams = [
            "{$this->environment}:messenger:async"             => $this->environment,
            "{$this->environment}:messenger:async_low"         => "{$this->environment}-low",
            "{$this->environment}:messenger:async_expressions" => "{$this->environment}-expressions",
            "{$this->environment}:messenger:async_profiles"    => "{$this->environment}-profiles",
        ];

        $dsn = $_ENV['MESSENGER_TRANSPORT_DSN'] ?? $_SERVER['MESSENGER_TRANSPORT_DSN'] ?? null;
        if (!$dsn) {
            $io->error('MESSENGER_TRANSPORT_DSN not set');
            return Command::FAILURE;
        }

        $redis = $this->connect($dsn, $io);
        if ($redis === null) {
            return Command::FAILURE;
        }

        $io->title("Messenger Streams (env: {$this->environment})");

        if ($discard && !$dryRun) {
            if (!$io->confirm('--discard will PERMANENTLY drop every undelivered message in every listed stream. Continue?', false)) {
                $io->warning('Aborted.');
                return Command::SUCCESS;
            }
        }

        foreach ($streams as $stream => $group) {
            $io->section($stream);

            if (!$redis->exists($stream)) {
                $io->text('<comment>stream does not exist yet</comment>');
                continue;
            }

            try {
                $groups = $redis->xInfo('GROUPS', $stream) ?: [];
            } catch (\Throwable $e) {
                $io->warning('xInfo failed: ' . $e->getMessage());
                continue;
            }

            if (!$groups) {
                $io->text('<comment>no consumer groups on this stream</comment>');
                continue;
            }

            foreach ($groups as $g) {
                $name    = $g['name']              ?? $g[1]  ?? '?';
                $pending = $g['pending']           ?? $g[5]  ?? 0;
                $lastId  = $g['last-delivered-id'] ?? $g[7]  ?? '?';
                $lag     = $g['lag']               ?? $g[11] ?? null;

                $isOurs = ($name === $group);

                $io->writeln(sprintf(
                    '  group <info>%s</info>%s  pending=<comment>%s</comment>  last-delivered=%s%s',
                    $name,
                    $isOurs ? ' (current)' : ' <fg=yellow>(foreign)</>',
                    $pending,
                    $lastId,
                    $lag !== null ? "  lag={$lag}" : ''
                ));

                // List consumers + their pending counts for diagnostics.
                try {
                    $consumers = $redis->xInfo('CONSUMERS', $stream, $name) ?: [];
                    foreach ($consumers as $c) {
                        $cname    = $c['name']    ?? $c[1] ?? '?';
                        $cpending = $c['pending'] ?? $c[3] ?? 0;
                        $cidle    = $c['idle']    ?? $c[5] ?? 0;
                        $io->writeln(sprintf('      consumer <info>%s</info>  pending=%d  idle=%dms', $cname, $cpending, $cidle));
                    }
                } catch (\Throwable) {
                    // best-effort only
                }

                // Foreign group cleanup
                if (!$isOurs && $cleanup) {
                    if ($dryRun) {
                        $io->note("would delete foreign group «{$name}»");
                    } else {
                        $redis->rawCommand('XGROUP', 'DESTROY', $stream, $name);
                        $io->success("deleted foreign group «{$name}»");
                    }
                    continue;
                }

                if (!$isOurs) {
                    continue;
                }

                // Safe reclaim: reassign stuck pendings to a live consumer name.
                if ($reclaim && (int) $pending > 0) {
                    $liveConsumer = sprintf('reclaim-%s-%d', gethostname() ?: 'cli', getmypid());
                    if ($dryRun) {
                        $io->note(sprintf('would XAUTOCLAIM pendings idle > %dms to consumer «%s»', $minIdle, $liveConsumer));
                    } else {
                        $total   = 0;
                        $cursor  = '0-0';
                        $claimed = 0;
                        do {
                            $res = $redis->rawCommand('XAUTOCLAIM', $stream, $name, $liveConsumer, (string) $minIdle, $cursor, 'COUNT', '100');
                            if (!is_array($res) || count($res) < 2) {
                                break;
                            }
                            $cursor  = (string) $res[0];
                            $claimed = is_array($res[1]) ? count($res[1]) : 0;
                            $total  += $claimed;
                        } while ($cursor !== '0-0' && $claimed > 0);
                        $io->success(sprintf('reclaimed %d pending entries to «%s» (workers will retry + ack)', $total, $liveConsumer));
                    }
                }

                // Destructive discard: skip the whole backlog.
                if ($discard) {
                    if ($dryRun) {
                        $io->note("would XGROUP SETID «{$name}» → + (drop backlog)");
                    } else {
                        $redis->rawCommand('XGROUP', 'SETID', $stream, $name, '+');
                        $io->warning("discarded backlog on «{$name}»");
                    }
                }
            }
        }

        $io->newLine();
        if (!$reclaim && !$cleanup && !$discard) {
            $io->info('Read-only diagnostic. Use --reclaim to safely re-deliver stuck pendings, --cleanup to drop foreign groups, --discard to wipe backlogs.');
        } elseif (!$dryRun) {
            $io->info('Done. Workers do not need to be restarted.');
        }

        return Command::SUCCESS;
    }

    private function connect(string $dsn, SymfonyStyle $io): ?\Redis
    {
        $parsed = parse_url($dsn);
        if ($parsed === false) {
            $io->error('Could not parse MESSENGER_TRANSPORT_DSN');
            return null;
        }

        $host = $parsed['host'] ?? '127.0.0.1';
        $port = (int) ($parsed['port'] ?? 6379);
        $pass = isset($parsed['pass']) ? urldecode($parsed['pass']) : null;
        if ($pass === null && isset($parsed['user'])) {
            $pass = urldecode($parsed['user']);
        }

        // DB index can come from the path (/N) or ?dbindex=N
        $db = 0;
        if (isset($parsed['path']) && preg_match('~/(\d+)~', $parsed['path'], $m)) {
            $db = (int) $m[1];
        }
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $q);
            if (isset($q['dbindex'])) {
                $db = (int) $q['dbindex'];
            }
        }

        try {
            $redis = new \Redis();
            $redis->connect($host, $port);
            if ($pass) {
                $redis->auth($pass);
            }
            if ($db > 0) {
                $redis->select($db);
            }
        } catch (\Throwable $e) {
            $io->error('Cannot connect to Redis: ' . $e->getMessage());
            return null;
        }

        $io->writeln(sprintf('<comment>Connected to %s:%d db=%d</comment>', $host, $port, $db));
        $io->newLine();

        return $redis;
    }
}
