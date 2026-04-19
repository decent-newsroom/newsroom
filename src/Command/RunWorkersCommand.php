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
 * Runs two Symfony Messenger consumer processes:
 *   - async: high-priority content fetches (articles, comments, media, magazines)
 *   - async_low_priority: gateway persistence, login warmup, relay lists
 *   - async_expressions: user-initiated expression evaluation (isolated so a
 *     backlog on `async` never delays the loading page)
 *
 * Relay subscriptions run in a separate service (worker-relay) via app:run-relay-workers.
 * Profile work runs in worker-profiles via app:run-profile-workers.
 *
 * Both consumers run in parallel and are automatically restarted on failure.
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
                '  - async: High-priority content fetches (articles, comments, media, magazines)' . "\n" .
                '  - async_low_priority: Gateway persistence, login warmup, relay lists' . "\n" .
                '  - async_expressions: User-initiated expression evaluation' . "\n\n" .
                'Relay subscriptions run in worker-relay (app:run-relay-workers).' . "\n" .
                'Profile work runs in worker-profiles (app:run-profile-workers).' . "\n" .
                'The relay gateway runs as a separate Docker service (relay-gateway).' . "\n" .
                'Both consumers restart automatically on failure.'
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
                'description' => 'Messenger consumer (async: articles, comments, media, magazines)',
            ],
            'messenger-low' => [
                'command' => [
                    'php', 'bin/console', 'messenger:consume', 'async_low_priority',
                    '-vv', '--memory-limit=128M', '--time-limit=3600',
                ],
                'description' => 'Messenger consumer (async_low_priority: gateway persistence, login warmup)',
            ],
            'messenger-expressions' => [
                'command' => [
                    'php', 'bin/console', 'messenger:consume', 'async_expressions',
                    '-vv', '--memory-limit=192M', '--time-limit=3600',
                ],
                'description' => 'Messenger consumer (async_expressions: user-initiated expression evaluation)',
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
