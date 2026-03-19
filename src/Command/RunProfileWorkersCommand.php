<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Dedicated worker for profile-related background tasks.
 *
 * Runs as its own Docker service (worker-profiles) so that heavy relay work
 * (batch metadata fetches for 25 pubkeys at a time) cannot starve the main
 * worker's messenger queues.
 *
 * Subprocesses:
 *   - Profile refresh daemon: periodically finds stale profiles and dispatches
 *     BatchUpdateProfileProjectionMessage to the async_profiles queue.
 *   - Messenger consumer (async_profiles): processes batch profile updates,
 *     individual profile projections, and cache revalidation messages.
 */
#[AsCommand(
    name: 'app:run-profile-workers',
    description: 'Run profile refresh daemon and async_profiles messenger consumer'
)]
class RunProfileWorkersCommand extends Command
{
    private array $processes = [];
    private bool $shouldStop = false;

    protected function configure(): void
    {
        $this
            ->addOption(
                'profile-interval',
                null,
                InputOption::VALUE_OPTIONAL,
                'Profile refresh interval in seconds',
                '600'
            )
            ->addOption(
                'profile-batch-size',
                null,
                InputOption::VALUE_OPTIONAL,
                'Profile refresh batch size',
                '100'
            )
            ->setHelp(
                'Runs the profile refresh daemon and the async_profiles messenger consumer.' . "\n\n" .
                'Workers:' . "\n" .
                '  - Profile refresh: Finds stale profiles and dispatches batch update messages' . "\n" .
                '  - Messenger consumer (async_profiles): Processes profile batch updates,' . "\n" .
                '    individual profile projections, and cache revalidation' . "\n\n" .
                'This command is designed to run as the worker-profiles Docker service,' . "\n" .
                'isolated from the main worker so heavy relay calls cannot block other queues.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleShutdownSignal']);
            pcntl_signal(SIGINT, [$this, 'handleShutdownSignal']);
        }

        $io->title('Profile Workers Manager');
        $io->info('Starting profile background workers...');
        $io->newLine();

        $interval = $input->getOption('profile-interval');
        $batchSize = $input->getOption('profile-batch-size');

        $workers = [
            'profile-refresh' => [
                'command' => [
                    'php', 'bin/console', 'app:profile-refresh-worker',
                    '--interval=' . $interval,
                    '--batch-size=' . $batchSize,
                ],
                'description' => 'Profile refresh daemon',
            ],
            'messenger-profiles' => [
                'command' => [
                    'php', 'bin/console', 'messenger:consume', 'async_profiles',
                    '-vv', '--memory-limit=128M', '--time-limit=3600',
                ],
                'description' => 'Messenger consumer (async_profiles)',
            ],
        ];

        // Display enabled workers
        $io->section('Enabled Workers');
        foreach ($workers as $name => $config) {
            $io->writeln(sprintf('  ✓ <info>%s</info>: %s', ucfirst($name), $config['description']));
        }
        $io->newLine();

        // Start all workers
        foreach ($workers as $name => $config) {
            $this->startWorker($name, $config, $io);
        }

        $io->success('All profile workers started. Monitoring...');
        $io->info('Press Ctrl+C to stop all workers gracefully.');
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
                        'Worker "%s" stopped (exit code: %d). Restarting...',
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
        $io->section('Shutting down profile workers...');
        foreach ($this->processes as $name => $process) {
            if ($process->isRunning()) {
                $io->writeln(sprintf('Stopping %s...', $name));
                $process->stop(10);
            }
        }

        $io->success('All profile workers stopped successfully.');
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

