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
 * Relay subscription worker that hydrates local data from the strfry relay.
 *
 * Maintains persistent WebSocket subscriptions to the local strfry relay,
 * processing incoming events to create/update articles, media, magazines,
 * and user context events in the database. Runs as its own Docker service
 * (worker-relay) so that long-lived relay connections don't share resources
 * with messenger consumers.
 *
 * Subprocesses:
 *   - Article hydration: subscribes to kind 30023 (longform articles)
 *   - Media hydration: subscribes to kinds 20, 21, 22 (images, video)
 *   - Magazine hydration: subscribes to kind 30040 (publication indices)
 *   - User context hydration: subscribes to user identity/social graph kinds
 *     (0, 3, 5, 10000–10063, 30003–30015, 30024, 34139, 39089)
 */
#[AsCommand(
    name: 'app:run-relay-workers',
    description: 'Run local relay subscription workers (articles, media, magazines, user context)'
)]
class RunRelayWorkersCommand extends Command
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
                'without-user-context',
                null,
                InputOption::VALUE_NONE,
                'Disable user context hydration worker'
            )
            ->addOption(
                'without-spells',
                null,
                InputOption::VALUE_NONE,
                'Disable spell hydration worker'
            )
            ->setHelp(
                'Runs local relay subscription workers in a single process.' . "\n\n" .
                'Workers:' . "\n" .
                '  - Article hydration: Subscribes to relay for article events (kind 30023)' . "\n" .
                '  - Media hydration: Subscribes to relay for media events (kinds 20, 21, 22)' . "\n" .
                '  - Magazine hydration: Subscribes to relay for magazine events (kind 30040)' . "\n" .
                '  - User context hydration: Subscribes to relay for user identity/social events' . "\n" .
                '    (follows, bookmarks, interests, relay lists, etc.)' . "\n" .
                '  - Spell hydration: Subscribes to relay for spell events (kind 777, NIP-A7)' . "\n\n" .
                'This command is designed to run as the worker-relay Docker service.' . "\n" .
                'Messenger consumers run in the worker service (app:run-workers).' . "\n" .
                'Profile work runs in worker-profiles (app:run-profile-workers).' . "\n" .
                'All subprocesses restart automatically on failure.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleShutdownSignal']);
            pcntl_signal(SIGINT, [$this, 'handleShutdownSignal']);
        }

        $io->title('Relay Subscription Workers Manager');
        $io->info('Starting relay subscription workers...');
        $io->newLine();

        $workers = [];

        if (!$input->getOption('without-articles')) {
            $workers['articles'] = [
                'command' => ['php', 'bin/console', 'articles:subscribe-local-relay', '-vv'],
                'description' => 'Article hydration worker',
            ];
        }

        if (!$input->getOption('without-media')) {
            $workers['media'] = [
                'command' => ['php', 'bin/console', 'media:subscribe-local-relay', '-vv'],
                'description' => 'Media hydration worker',
            ];
        }

        if (!$input->getOption('without-magazines')) {
            $workers['magazines'] = [
                'command' => ['php', 'bin/console', 'magazines:subscribe-local-relay', '-vv'],
                'description' => 'Magazine hydration worker',
            ];
        }

        if (!$input->getOption('without-user-context')) {
            $workers['user-context'] = [
                'command' => ['php', 'bin/console', 'user-context:subscribe-local-relay', '-vv'],
                'description' => 'User context hydration worker',
            ];
        }

        if (!$input->getOption('without-spells')) {
            $workers['spells'] = [
                'command' => ['php', 'bin/console', 'spells:subscribe-local-relay', '-vv'],
                'description' => 'Spell hydration worker',
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

        $io->success('All relay workers started. Monitoring...');
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
        $io->section('Shutting down relay workers...');
        foreach ($this->processes as $name => $process) {
            if ($process->isRunning()) {
                $io->writeln(sprintf('Stopping %s...', $name));
                $process->stop(10);
            }
        }

        $io->success('All relay workers stopped successfully.');
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

