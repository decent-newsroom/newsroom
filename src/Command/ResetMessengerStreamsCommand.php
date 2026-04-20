<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
        #[Autowire('%env(MESSENGER_TRANSPORT_DSN)%')]
        private readonly string $messengerTransportDsn,
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
            ->addOption('prune', null, InputOption::VALUE_NONE, 'Remove stale consumers (idle > threshold, pending=0) from current groups')
            ->addOption('prune-idle', null, InputOption::VALUE_REQUIRED, 'Minimum idle time in milliseconds for --prune (default: 1 hour)', '3600000')
            ->addOption('discard', null, InputOption::VALUE_NONE, 'DESTRUCTIVE: skip all undelivered messages (XGROUP SETID ... +)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun  = (bool) $input->getOption('dry-run');
        $reclaim = (bool) $input->getOption('reclaim');
        $cleanup = (bool) $input->getOption('cleanup');
        $prune   = (bool) $input->getOption('prune');
        $discard = (bool) $input->getOption('discard');
        $minIdle = (int) $input->getOption('min-idle');
        $pruneIdle = (int) $input->getOption('prune-idle');

        // Every messenger transport defined in config/packages/messenger.yaml.
        // Keep this in sync with that file.
        //
        // Expected layout (separate streams per transport):
        $streams = [
            "{$this->environment}:messenger:async"             => $this->environment,
            "{$this->environment}:messenger:async_low"         => "{$this->environment}-low",
            "{$this->environment}:messenger:async_expressions" => "{$this->environment}-expressions",
            "{$this->environment}:messenger:async_profiles"    => "{$this->environment}-profiles",
        ];

        // Legacy / misconfigured layout: all groups share a single stream
        // whose name comes from the DSN path (e.g. "/0" → stream "0",
        // "/messages" → stream "messages"). Detect this so we can still
        // inspect the actual state even when the config drifted.
        // We add all known group names as candidates for this stream.
        $parsed = parse_url($this->messengerTransportDsn);
        $dsnStream = isset($parsed['path']) ? ltrim($parsed['path'], '/') : null;
        $allGroups = [
            $this->environment,
            "{$this->environment}-low",
            "{$this->environment}-expressions",
            "{$this->environment}-profiles",
        ];
        if ($dsnStream !== null && $dsnStream !== '' && !isset($streams[$dsnStream])) {
            // Map the DSN-derived stream to all expected groups (single-stream layout).
            $streams[$dsnStream] = $allGroups;
        }

        $dsn = $this->messengerTransportDsn;
        if (!$dsn) {
            $io->error('MESSENGER_TRANSPORT_DSN not set');
            return Command::FAILURE;
        }

        $redis = $this->connect($dsn, $io);
        if ($redis === null) {
            return Command::FAILURE;
        }

        $io->title("Messenger Streams (env: {$this->environment})");

        // Show the resolved DSN (mask password) so the operator can verify
        // the console container is pointing at the same Redis as the workers.
        $maskedDsn = preg_replace('#://:[^@]*@#', '://:<redacted>@', $dsn);
        $io->writeln(sprintf('<comment>DSN: %s</comment>', $maskedDsn));
        $io->newLine();

        // ── DSN health check ────────────────────────────────────────────
        // Symfony's Redis transport parses the DSN path as: /stream/group/consumer
        // A non-empty path silently overrides the per-transport `stream:` option
        // from messenger.yaml, collapsing all queues into one shared stream.
        $dsnPath = trim($parsed['path'] ?? '', '/');
        if ($dsnPath !== '') {
            $io->error([
                sprintf('⚠ MESSENGER_TRANSPORT_DSN has path "/%s"', $dsnPath),
                'Symfony uses this as the stream name, OVERRIDING the per-transport',
                '"stream:" option in messenger.yaml. All four transports share one stream!',
                '',
                'Fix: remove the path from the DSN.',
                ctype_digit($dsnPath)
                    ? sprintf('For DB index %s, use ?dbindex=%s instead.', $dsnPath, $dsnPath)
                    : 'If this was intended as a stream name, set it via the stream: option per transport.',
            ]);
        } else {
            $io->writeln('  <info>✓</info> DSN has no path — per-transport stream names from messenger.yaml will be used');
        }

        // Verify all configured stream names are unique
        $streamNames = array_keys($streams);
        $uniqueNames = array_unique($streamNames);
        if (count($streamNames) === count($uniqueNames)) {
            $io->writeln(sprintf('  <info>✓</info> All %d transport streams have unique names', count($streamNames)));
        } else {
            $dupes = array_diff_assoc($streamNames, $uniqueNames);
            $io->error(sprintf('Duplicate stream names detected: %s', implode(', ', $dupes)));
        }
        $io->newLine();

        // Auto-discovery: scan every DB (0..15) for stream-type keys that look
        // like messenger streams. Helps spot DSN / DB-index mismatches between
        // the console and the worker containers.
        $this->discoverStreams($redis, $io, $streams);

        if ($discard && !$dryRun) {
            if (!$io->confirm('--discard will PERMANENTLY drop every undelivered message in every listed stream. Continue?', false)) {
                $io->warning('Aborted.');
                return Command::SUCCESS;
            }
        }

        foreach ($streams as $stream => $groupOrGroups) {
            $io->section($stream);
            $ourGroups = is_array($groupOrGroups) ? $groupOrGroups : [$groupOrGroups];

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

                $isOurs = in_array($name, $ourGroups, true);

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
                        $isStale  = $cpending === 0 && $cidle > $pruneIdle;
                        $io->writeln(sprintf(
                            '      consumer <info>%s</info>  pending=%d  idle=%dms%s',
                            $cname, $cpending, $cidle,
                            $isStale ? '  <fg=yellow>← stale</>' : '',
                        ));
                    }

                    // Prune stale consumers (idle > threshold, pending=0)
                    if ($prune && $isOurs) {
                        foreach ($consumers as $c) {
                            $cname    = $c['name']    ?? $c[1] ?? '?';
                            $cpending = $c['pending'] ?? $c[3] ?? 0;
                            $cidle    = $c['idle']    ?? $c[5] ?? 0;
                            if ($cpending === 0 && $cidle > $pruneIdle) {
                                if ($dryRun) {
                                    $io->note(sprintf('would XGROUP DELCONSUMER «%s» from group «%s» (idle %ds, pending=0)', $cname, $name, intdiv($cidle, 1000)));
                                } else {
                                    $redis->rawCommand('XGROUP', 'DELCONSUMER', $stream, $name, $cname);
                                    $io->writeln(sprintf('      <fg=green>✓ pruned stale consumer «%s» (idle %ds)</>', $cname, intdiv($cidle, 1000)));
                                }
                            }
                        }
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
        if (!$reclaim && !$cleanup && !$discard && !$prune) {
            $io->info('Read-only diagnostic. Use --reclaim to safely re-deliver stuck pendings, --prune to remove stale consumers, --cleanup to drop foreign groups, --discard to wipe backlogs.');
        } elseif (!$dryRun) {
            $io->info('Done. Workers do not need to be restarted.');
        }

        return Command::SUCCESS;
    }

    /**
     * Scan every Redis DB for stream-type keys that look like messenger
     * streams, and list them. Purely diagnostic — does not modify state.
     */
    private function discoverStreams(\Redis $redis, SymfonyStyle $io, array $streams): void
    {
        $io->section('Stream discovery (scanning all DBs for messenger-like streams)');

        $foundAny = false;
        $originalDb = 0;

        // Figure out current DB from CLIENT INFO (best-effort) so we can restore it.
        try {
            $clientInfo = $redis->rawCommand('CLIENT', 'INFO');
            if (is_string($clientInfo) && preg_match('/\sdb=(\d+)/', $clientInfo, $m)) {
                $originalDb = (int) $m[1];
            }
        } catch (\Throwable) {
            // ignore
        }

        // Direct EXISTS check for expected stream names on the current DB first.
        // This is the most reliable method — no SCAN iteration issues.
        $io->writeln('  <comment>Direct check for expected streams on current DB:</comment>');
        foreach ($streams as $streamName => $_) {
            try {
                $exists = $redis->exists($streamName);
                $type   = $exists ? $redis->type($streamName) : null;
                $len    = ($exists && $type === \Redis::REDIS_STREAM) ? $redis->xLen($streamName) : null;
                $io->writeln(sprintf(
                    '    %s  exists=%s  type=%s%s',
                    $streamName,
                    $exists ? '<info>yes</info>' : '<fg=yellow>no</>',
                    match($type) {
                        \Redis::REDIS_STREAM => 'stream',
                        \Redis::REDIS_STRING => 'string',
                        \Redis::REDIS_LIST   => 'list',
                        \Redis::REDIS_SET    => 'set',
                        \Redis::REDIS_ZSET   => 'zset',
                        \Redis::REDIS_HASH   => 'hash',
                        null                 => '-',
                        default              => "unknown({$type})",
                    },
                    $len !== null ? "  xlen={$len}" : '',
                ));
                if ($exists) {
                    $foundAny = true;
                }
            } catch (\Throwable $e) {
                $io->writeln(sprintf('    %s  <error>error: %s</error>', $streamName, $e->getMessage()));
            }
        }
        $io->newLine();

        // Enable SCAN_RETRY so phpredis automatically retries when an
        // iteration returns no matches but the cursor hasn't been exhausted.
        // Without this, SCAN can exit after one empty batch in large keyspaces.
        try {
            $redis->setOption(\Redis::OPT_SCAN, \Redis::SCAN_RETRY);
        } catch (\Throwable) {
            // older phpredis versions may not support this — fall through
        }

        for ($db = 0; $db < 16; $db++) {
            try {
                if (!$redis->select($db)) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }

            $matches = [];

            // Primary: use KEYS for a reliable one-shot lookup (safe here
            // because we only run this from an operator CLI, not hot path).
            try {
                $found = $redis->keys('*messenger*');
                if (is_array($found)) {
                    foreach ($found as $key) {
                        try {
                            if ($redis->type($key) === \Redis::REDIS_STREAM) {
                                $matches[$key] = true;
                            }
                        } catch (\Throwable) {
                            // ignore
                        }
                    }
                }
            } catch (\Throwable) {
                // Fallback to SCAN if KEYS is disabled (e.g. rename-command).
                $cursor = null;
                do {
                    $keys = $redis->scan($cursor, '*messenger*', 500);
                    if (is_array($keys)) {
                        foreach ($keys as $key) {
                            try {
                                if ($redis->type($key) === \Redis::REDIS_STREAM) {
                                    $matches[$key] = true;
                                }
                            } catch (\Throwable) {
                                // ignore
                            }
                        }
                    }
                } while ($cursor > 0);
            }

            if ($matches) {
                $foundAny = true;
                $io->writeln(sprintf('  <info>db=%d</info>', $db));
                foreach (array_keys($matches) as $key) {
                    try {
                        $len = $redis->xLen($key);
                    } catch (\Throwable) {
                        $len = '?';
                    }
                    $io->writeln(sprintf('    %s  (xlen=%s)', $key, $len));
                }
            }
        }

        if (!$foundAny) {
            $io->writeln('  <comment>no messenger-like streams found in any DB (0-15)</comment>');

            // Fallback: try KEYS on the original DB as a sanity check.
            try {
                $redis->select($originalDb);
                $allKeys = $redis->keys('*');
                $io->writeln(sprintf('  <comment>total keys in db=%d: %d</comment>', $originalDb, is_array($allKeys) ? count($allKeys) : 0));
                if (is_array($allKeys) && count($allKeys) > 0) {
                    $sample = array_slice($allKeys, 0, 10);
                    $io->writeln(sprintf('  <comment>sample keys: %s</comment>', implode(', ', $sample)));
                }
            } catch (\Throwable) {
                // ignore
            }

            $io->writeln('  <comment>hint: the console container may be pointing at a different Redis instance than the workers. Compare MESSENGER_TRANSPORT_DSN in the php and worker services.</comment>');
        }

        // Restore to the DSN-selected DB so the rest of the command operates
        // against the originally-configured database.
        try {
            $redis->select($originalDb);
        } catch (\Throwable) {
            // ignore
        }
        $io->newLine();
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
