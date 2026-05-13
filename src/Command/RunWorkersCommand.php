<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Messenger consumer worker for async and async_low_priority queues.
 *
 * Runs consumer processes in parallel:
 *   - async: strictly user-facing fetches (comments, author articles/content,
 *     event-from-relays). Short queue, fast turnaround.
 *   - async_low_priority: all background work — fan-out notifications,
 *     media discovery, magazine projection, embed prefetch, highlights,
 *     gateway persistence, login warmup, relay lists. High-volume but not
 *     user-blocking; dedicated consumer keeps it from starving `async`.
 *   - async_expressions: user-initiated expression evaluation (isolated so a
 *     backlog on `async` never delays the loading page).
 *   - async_relay_feeds (×3): relay feed WebSocket subscriptions. Each handler
 *     blocks for ~4.5 min, so three parallel consumers allow three feeds to run
 *     concurrently without queuing behind each other or blocking any other queue.
 *
 * Relay subscriptions run in a separate service (worker-relay) via app:run-relay-workers.
 * Profile work runs in worker-profiles via app:run-profile-workers.
 *
 * All consumers restart automatically on failure.
 */
#[AsCommand(
    name: 'app:run-workers',
    description: 'Run messenger consumers (async + async_low_priority)'
)]
class RunWorkersCommand extends Command
{
    private array $processes = [];
    private bool $shouldStop = false;

    protected function configure(): void
    {
        $this
            ->setHelp(
                    'Runs Symfony Messenger consumers for the async and async_low_priority queues.' . "\n\n" .
                    'Consumers:' . "\n" .
                    '  - async: User-facing fetches (comments, author articles/content, event-from-relays)' . "\n" .
                    '  - async_low_priority: Background work (fan-out notifications, media, magazines, embeds,' . "\n" .
                    '                        highlights, gateway persistence, login warmup, relay lists)' . "\n" .
                    '  - async_expressions: User-initiated expression evaluation' . "\n" .
                    '  - async_relay_feeds (×3): Relay feed WebSocket subscriptions (4.5 min each, 3 concurrent)' . "\n\n" .
                    'Relay subscriptions run in worker-relay (app:run-relay-workers).' . "\n" .
                    'Profile work runs in worker-profiles (app:run-profile-workers).' . "\n" .
                    'The relay gateway runs as a separate Docker service (relay-gateway).' . "\n" .
                    'All consumers restart automatically on failure.'
                );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleShutdownSignal']);
            pcntl_signal(SIGINT, [$this, 'handleShutdownSignal']);
        }

        $io->title('Messenger Workers Manager');
        $io->info('Starting messenger consumers...');
        $io->newLine();

        $workers = [
            'messenger' => [
                    'command' => [
                        'php', 'bin/console', 'messenger:consume', 'async',
                        '-vv', '--memory-limit=256M', '--time-limit=3600',
                    ],
                    'description' => 'Messenger consumer (async: comments, author articles/content, event-from-relays)',
                ],
                'messenger-low' => [
                    'command' => [
                        'php', 'bin/console', 'messenger:consume', 'async_low_priority',
                        '-vv', '--memory-limit=192M', '--time-limit=3600',
                    ],
                    'description' => 'Messenger consumer (async_low_priority: fan-out, media, magazines, embeds, highlights, warmup)',
                ],
            'messenger-expressions' => [
                'command' => [
                    'php', 'bin/console', 'messenger:consume', 'async_expressions',
                    '-vv', '--memory-limit=192M', '--time-limit=3600',
                ],
                'description' => 'Messenger consumer (async_expressions: user-initiated expression evaluation)',
            ],
            // Three parallel consumers for relay feeds.
            // Each StartRelayFeedMessage blocks ~4.5 min (open WebSocket), so three
            // workers means three feeds can run concurrently with no queuing delay.
            'relay-feed-1' => [
                'command' => [
                    'php', 'bin/console', 'messenger:consume', 'async_relay_feeds',
                    '-vv', '--memory-limit=128M', '--time-limit=3600',
                ],
                'description' => 'Relay feed consumer 1 (async_relay_feeds)',
            ],
            'relay-feed-2' => [
                'command' => [
                    'php', 'bin/console', 'messenger:consume', 'async_relay_feeds',
                    '-vv', '--memory-limit=128M', '--time-limit=3600',
                ],
                'description' => 'Relay feed consumer 2 (async_relay_feeds)',
            ],
            'relay-feed-3' => [
                'command' => [
                    'php', 'bin/console', 'messenger:consume', 'async_relay_feeds',
                    '-vv', '--memory-limit=128M', '--time-limit=3600',
                ],
                'description' => 'Relay feed consumer 3 (async_relay_feeds)',
            ],
        ];

        // Display enabled workers
        $io->section('Enabled Consumers');
        foreach ($workers as $name => $config) {
            $io->writeln(sprintf('  ✓ <info>%s</info>: %s', ucfirst($name), $config['description']));
        }
        $io->newLine();

        // Start all workers
        foreach ($workers as $name => $config) {
            $this->startWorker($name, $config, $io);
        }

        $io->success('All messenger consumers started. Monitoring...');
        $io->info('Press Ctrl+C to stop all consumers gracefully.');
        $io->newLine();

        // Monitor and auto-restart
        while (!$this->shouldStop) {
            foreach ($this->processes as $name => $process) {
                if (!$process->isRunning()) {
                    if ($this->shouldStop) {
                        break;
                    }

                    $exitCode = $process->getExitCode();
                    $io->warning(sprintf(
                        'Consumer "%s" stopped (exit code: %d). Restarting...',
                        $name,
                        $exitCode
                    ));

                    $this->startWorker($name, $workers[$name], $io);
                    sleep(2);
                }
            }

            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            usleep(500000); // 0.5s
        }

        // Graceful shutdown
        $io->section('Shutting down consumers...');
        foreach ($this->processes as $name => $process) {
            if ($process->isRunning()) {
                $io->writeln(sprintf('Stopping %s...', $name));
                $process->stop(10);
            }
        }

        $io->success('All messenger consumers stopped successfully.');
        return Command::SUCCESS;
    }

    private function startWorker(string $name, array $config, SymfonyStyle $io): void
    {
        $process = new Process($config['command']);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);

        $process->start(function ($type, $buffer) use ($name, $io) {
            if (Process::ERR === $type) {
                $io->writeln(sprintf('<error>[%s ERROR]</error> %s', strtoupper($name), trim($buffer)));
            } else {
                $io->writeln(sprintf('<comment>[%s]</comment> %s', strtoupper($name), trim($buffer)));
            }
        });

        $this->processes[$name] = $process;
        $io->writeln(sprintf('  → Started %s (PID: %d)', $config['description'], $process->getPid()));
    }

    public function handleShutdownSignal(int $signal): void
    {
        $this->shouldStop = true;
    }
}
