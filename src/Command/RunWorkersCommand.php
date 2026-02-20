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
 * Consolidated worker that runs multiple long-lived daemon processes.
 *
 * This command manages:
 * - Article hydration (subscribes to relay for article events)
 * - Media hydration (subscribes to relay for media events)
 * - Profile refresh (periodic profile metadata updates)
 * - Messenger consumer (async job queue processing)
 *
 * All subprocesses run in parallel and are automatically restarted on failure.
 */
#[AsCommand(
    name: 'app:run-workers',
    description: 'Run all background workers (articles, media, profiles, messenger)'
)]
class RunWorkersCommand extends Command
{
    private array $processes = [];
    private bool $shouldStop = false;

    protected function configure(): void
    {
        $this
            ->addOption(
                'without-articles',
                null,
                InputOption::VALUE_NONE,
                'Disable article hydration worker'
            )
            ->addOption(
                'without-media',
                null,
                InputOption::VALUE_NONE,
                'Disable media hydration worker'
            )
            ->addOption(
                'without-magazines',
                null,
                InputOption::VALUE_NONE,
                'Disable magazine hydration worker'
            )
            ->addOption(
                'without-profiles',
                null,
                InputOption::VALUE_NONE,
                'Disable profile refresh worker'
            )
            ->addOption(
                'without-messenger',
                null,
                InputOption::VALUE_NONE,
                'Disable messenger consumer worker'
            )
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
                'This command runs all background workers in a single process.' . "\n\n" .
                'Workers:' . "\n" .
                '  - Article hydration: Subscribes to relay for article events (kind 30023)' . "\n" .
                '  - Media hydration: Subscribes to relay for media events (kinds 20, 21, 22)' . "\n" .
                '  - Magazine hydration: Subscribes to relay for magazine events (kind 30040)' . "\n" .
                '  - Profile refresh: Periodic profile metadata updates' . "\n" .
                '  - Messenger consumer: Async job queue processing' . "\n\n" .
                'All workers restart automatically on failure.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Register signal handlers for graceful shutdown
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleShutdownSignal']);
            pcntl_signal(SIGINT, [$this, 'handleShutdownSignal']);
        }

        $io->title('Background Workers Manager');
        $io->info('Starting all background workers...');
        $io->newLine();

        // Define workers to run
        $workers = [];

        if (!$input->getOption('without-articles')) {
            $workers['articles'] = [
                'command' => ['php', 'bin/console', 'articles:subscribe-local-relay', '-vv'],
                'description' => 'Article hydration worker'
            ];
        }

        if (!$input->getOption('without-media')) {
            $workers['media'] = [
                'command' => ['php', 'bin/console', 'media:subscribe-local-relay', '-vv'],
                'description' => 'Media hydration worker'
            ];
        }

        if (!$input->getOption('without-magazines')) {
            $workers['magazines'] = [
                'command' => ['php', 'bin/console', 'magazines:subscribe-local-relay', '-vv'],
                'description' => 'Magazine hydration worker'
            ];
        }

        if (!$input->getOption('without-profiles')) {
            $interval = $input->getOption('profile-interval');
            $batchSize = $input->getOption('profile-batch-size');
            $workers['profiles'] = [
                'command' => [
                    'php', 'bin/console', 'app:profile-refresh-worker',
                    '--interval=' . $interval,
                    '--batch-size=' . $batchSize
                ],
                'description' => 'Profile refresh worker'
            ];
        }

        if (!$input->getOption('without-messenger')) {
            $workers['messenger'] = [
                'command' => [
                    'php', 'bin/console', 'messenger:consume', 'async', 'async_low_priority',
                    '-vv', '--memory-limit=256M', '--time-limit=3600'
                ],
                'description' => 'Messenger consumer worker'
            ];
        }

        if (empty($workers)) {
            $io->error('No workers enabled. Use --without-* options to disable specific workers.');
            return Command::FAILURE;
        }

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

        $io->success('All workers started. Monitoring...');
        $io->info('Press Ctrl+C to stop all workers gracefully.');
        $io->newLine();

        // Monitor workers and restart on failure
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

                    // Restart the worker
                    $this->startWorker($name, $workers[$name], $io);

                    // Small delay to prevent rapid restart loops
                    sleep(2);
                }
            }

            // Check for signals (graceful shutdown)
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            usleep(500000); // Sleep 0.5 seconds
        }

        // Graceful shutdown
        $io->section('Shutting down workers...');
        foreach ($this->processes as $name => $process) {
            if ($process->isRunning()) {
                $io->writeln(sprintf('Stopping %s...', $name));
                $process->stop(10); // 10 second timeout
            }
        }

        $io->success('All workers stopped successfully.');
        return Command::SUCCESS;
    }

    private function startWorker(string $name, array $config, SymfonyStyle $io): void
    {
        $process = new Process($config['command']);
        $process->setTimeout(null); // No timeout - runs indefinitely
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
