<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Diagnostic command — inspect the relay gateway's Redis streams in real time.
 *
 * Usage:
 *   bin/console app:relay-gateway:status           # one-shot snapshot
 *   bin/console app:relay-gateway:status --watch   # refresh every 2s
 *   bin/console app:relay-gateway:status --send-warm --pubkey=<hex> --relays=wss://r1,wss://r2
 *   bin/console app:relay-gateway:status --send-ping  # write a test publish to request stream
 */
#[AsCommand(
    name: 'app:relay-gateway:status',
    description: 'Show relay gateway stream state and optionally inject test commands',
)]
class RelayGatewayStatusCommand extends Command
{
    private const REQUEST_STREAM = 'relay:requests';
    private const CONTROL_STREAM = 'relay:control';
    private const RESPONSE_PREFIX = 'relay:responses:';

    public function __construct(private readonly \Redis $redis)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('watch', 'w', InputOption::VALUE_NONE, 'Refresh every 2 seconds (Ctrl+C to stop)')
            ->addOption('send-warm', null, InputOption::VALUE_NONE, 'Write a warm command to relay:control')
            ->addOption('pubkey', null, InputOption::VALUE_REQUIRED, 'Pubkey hex for --send-warm')
            ->addOption('relays', null, InputOption::VALUE_REQUIRED, 'Comma-separated relay URLs for --send-warm')
            ->addOption('send-ping', null, InputOption::VALUE_NONE, 'Write a dummy publish to relay:requests and show response');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $watch = $input->getOption('watch');

        if ($input->getOption('send-warm')) {
            return $this->sendWarm($io, $input);
        }

        if ($input->getOption('send-ping')) {
            return $this->sendPing($io);
        }

        do {
            if ($watch) {
                // Clear screen
                $output->write("\033[2J\033[H");
            }

            $this->printStatus($io);

            if ($watch) {
                sleep(2);
            }
        } while ($watch);

        return Command::SUCCESS;
    }

    private function printStatus(SymfonyStyle $io): void
    {
        $io->title('Relay Gateway Status — ' . date('H:i:s'));

        // ── Stream lengths ────────────────────────────────────────────────────
        $io->section('Redis Streams');
        $cursorKeys = [
            self::REQUEST_STREAM => 'relay_gateway:cursor:requests',
            self::CONTROL_STREAM => 'relay_gateway:cursor:control',
        ];
        $table = [];
        foreach ([self::REQUEST_STREAM, self::CONTROL_STREAM] as $stream) {
            try {
                $len  = $this->redis->xLen($stream);
                $info = $this->redis->xInfo('STREAM', $stream);
                $lastId = $info['last-generated-id'] ?? 'n/a';

                // Read the gateway's cursor to determine if it's caught up
                $cursor = $this->redis->get($cursorKeys[$stream]);
                if ($cursor) {
                    // Count entries after the cursor to find actual pending
                    $pending = 0;
                    try {
                        // xRange from cursor+1 (exclusive) to end, limited
                        $afterCursor = $this->redis->xRange($stream, $this->incrementStreamId($cursor), '+', 100);
                        $pending = $afterCursor ? count($afterCursor) : 0;
                    } catch (\Throwable) {}

                    $status = $pending === 0 ? '✓ caught up' : sprintf('%d pending', $pending);
                } else {
                    $status = '— (no cursor)';
                }

                $table[] = [$stream, $len . ' (buffer)', $lastId, $status];
            } catch (\Throwable) {
                $table[] = [$stream, 'n/a', 'n/a', '—'];
            }
        }
        $io->table(['Stream', 'Length', 'Last ID', 'Gateway'], $table);

        // ── Gateway heartbeat (is it consuming?) ──────────────────────────────
        $io->section('Gateway Process');
        try {
            $heartbeat = $this->redis->get('relay_gateway:heartbeat');
            if ($heartbeat) {
                $ago = time() - (int) $heartbeat;
                if ($ago < 120) {
                    $io->text(sprintf('<info>✓ Gateway alive</info> — last heartbeat %ds ago', $ago));
                } else {
                    $io->text(sprintf('<error>✗ Gateway stale</error> — last heartbeat %ds ago (>120s)', $ago));
                }
            } else {
                $io->text('<error>✗ No gateway heartbeat found.</error> Is the relay-gateway service running?');
                $io->text('  Start with: <comment>docker compose --profile gateway up -d</comment>');
            }
        } catch (\Throwable $e) {
            $io->text('<error>' . $e->getMessage() . '</error>');
        }

        // ── Last 5 request messages ───────────────────────────────────────────
        $io->section('Last 5 Request Messages');
        try {
            $msgs = $this->redis->xRevRange(self::REQUEST_STREAM, '+', '-', 5);
            if (empty($msgs)) {
                $io->text('<comment>No messages in stream.</comment>');
            } else {
                foreach ($msgs as $id => $data) {
                    $io->text(sprintf(
                        '  <info>%s</info>  action=<comment>%s</comment>  id=%s  relays=%s',
                        $id,
                        $data['action'] ?? '?',
                        substr($data['id'] ?? '', 0, 16),
                        substr($data['relays'] ?? '', 0, 60),
                    ));
                }
            }
        } catch (\Throwable $e) {
            $io->text('<error>' . $e->getMessage() . '</error>');
        }

        // ── Last 5 control messages ───────────────────────────────────────────
        $io->section('Last 5 Control Messages');
        try {
            $msgs = $this->redis->xRevRange(self::CONTROL_STREAM, '+', '-', 5);
            if (empty($msgs)) {
                $io->text('<comment>No messages in stream.</comment>');
            } else {
                foreach ($msgs as $id => $data) {
                    $io->text(sprintf(
                        '  <info>%s</info>  action=<comment>%s</comment>  pubkey=%s  relays=%s',
                        $id,
                        $data['action'] ?? '?',
                        substr($data['pubkey'] ?? '', 0, 16),
                        substr($data['relays'] ?? '', 0, 80),
                    ));
                }
            }
        } catch (\Throwable $e) {
            $io->text('<error>' . $e->getMessage() . '</error>');
        }

        // ── Open response streams ─────────────────────────────────────────────
        $io->section('Open Response Streams');
        try {
            $keys = $this->redis->keys(self::RESPONSE_PREFIX . '*');
            if (empty($keys)) {
                $io->text('<comment>None (normal — response streams are transient, 60s TTL).</comment>');
            } else {
                $rows = [];
                foreach (array_slice($keys, 0, 10) as $key) {
                    $len = $this->redis->xLen($key);
                    $ttl = $this->redis->ttl($key);
                    $rows[] = [basename($key), $len, $ttl . 's'];
                }
                $io->table(['Correlation ID', 'Messages', 'TTL'], $rows);
            }
        } catch (\Throwable $e) {
            $io->text('<error>' . $e->getMessage() . '</error>');
        }

        // ── Health store summary ──────────────────────────────────────────────
        $io->section('Health Store (relay_health:*)');
        try {
            $keys = $this->redis->keys('relay_health:*');
            if (empty($keys)) {
                $io->text('<comment>No relay health data.</comment>');
            } else {
                $rows = [];
                foreach ($keys as $key) {
                    $data = $this->redis->hGetAll($key);
                    $url  = str_replace('relay_health:', '', $key);
                    $rows[] = [
                        $url,
                        $data['auth_required'] ?? '0',
                        $data['consecutive_failures'] ?? '0',
                        $data['auth_status'] ?? 'unknown',
                        isset($data['last_success']) ? date('H:i:s', (int) $data['last_success']) : '—',
                        isset($data['last_event_received']) ? date('H:i:s', (int) $data['last_event_received']) : '—',
                        isset($data['avg_latency_ms']) ? round((float) $data['avg_latency_ms']) . 'ms' : '—',
                    ];
                }
                $io->table(['Relay', 'AuthReq', 'Failures', 'AuthStatus', 'LastSuccess', 'LastEvent', 'Latency'], $rows);
            }
        } catch (\Throwable $e) {
            $io->text('<error>' . $e->getMessage() . '</error>');
        }
    }

    private function sendWarm(SymfonyStyle $io, InputInterface $input): int
    {
        $pubkey = $input->getOption('pubkey');
        $relays = $input->getOption('relays');

        if (!$pubkey || !$relays) {
            $io->error('--pubkey and --relays are required for --send-warm');
            return Command::FAILURE;
        }

        $relayList = array_map('trim', explode(',', $relays));

        $id = $this->redis->xAdd(self::CONTROL_STREAM, '*', [
            'action' => 'warm',
            'pubkey' => $pubkey,
            'relays' => json_encode($relayList),
        ]);

        $io->success(sprintf(
            'Warm command written to %s (id: %s) for %d relay(s): %s',
            self::CONTROL_STREAM,
            $id,
            count($relayList),
            implode(', ', $relayList),
        ));
        $io->text('Watch the gateway logs for "Gateway: queueing user connections for warm-up" and "Gateway: opened queued connection".');

        return Command::SUCCESS;
    }

    private function sendPing(SymfonyStyle $io): int
    {
        $correlationId = sprintf('%s-%s', date('YmdHis'), substr(md5(uniqid()), 0, 8));

        $io->text(sprintf('Writing test publish to %s (correlation: %s)...', self::REQUEST_STREAM, $correlationId));

        $this->redis->xAdd(self::REQUEST_STREAM, '*', [
            'id'      => $correlationId,
            'action'  => 'publish',
            'relays'  => json_encode(['ws://strfry:7777']),
            'event'   => json_encode(['id' => str_repeat('0', 64), 'kind' => 1, 'content' => 'gateway ping']),
            'pubkey'  => '',
            'timeout' => '5',
        ]);

        $io->text('Waiting up to 8s for gateway response...');

        $responseKey = self::RESPONSE_PREFIX . $correlationId;
        $deadline = time() + 8;
        $got = false;

        while (time() < $deadline) {
            $result = $this->redis->xRead([$responseKey => '0-0'], 10, 1000);
            if ($result && isset($result[$responseKey])) {
                $io->success('Gateway responded:');
                foreach ($result[$responseKey] as $msgId => $data) {
                    $io->text(sprintf('  [%s] %s', $msgId, json_encode($data)));
                }
                $got = true;
                break;
            }
        }

        if (!$got) {
            $io->error('No response from gateway within 8s. Is app:relay-gateway running?');
            $io->text('Check: docker compose logs relay-gateway');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Increment a Redis stream ID by 1 sequence number so xRange excludes
     * the given ID (i.e. returns only entries strictly after it).
     *
     * Stream IDs are "{timestamp_ms}-{seq}". We increment the seq part.
     */
    private function incrementStreamId(string $id): string
    {
        $parts = explode('-', $id, 2);
        if (count($parts) !== 2) {
            return $id;
        }
        return $parts[0] . '-' . ((int) $parts[1] + 1);
    }
}

