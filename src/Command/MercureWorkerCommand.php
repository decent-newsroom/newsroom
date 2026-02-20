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
 * Dedicated Mercure worker that only runs the messenger consumer.
 * This is useful for testing and debugging Mercure-related message handling.
 *
 * Usage:
 *   php bin/console app:mercure-worker
 *   php bin/console app:mercure-worker --test  # Run a quick Mercure publish test first
 */
#[AsCommand(
    name: 'app:mercure-worker',
    description: 'Run a dedicated worker for Mercure-related message processing'
)]
class MercureWorkerCommand extends Command
{
    public function __construct(
        private readonly \Symfony\Component\Mercure\HubInterface $hub
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'test',
                't',
                InputOption::VALUE_NONE,
                'Run a Mercure publish test before starting the consumer'
            )
            ->addOption(
                'queues',
                'q',
                InputOption::VALUE_OPTIONAL,
                'Comma-separated list of queues to consume (default: async,async_low_priority)',
                'async,async_low_priority'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Number of messages to consume (0 = unlimited)',
                '0'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Mercure Worker');

        // Display environment info
        $io->section('Environment');
        $io->table(
            ['Variable', 'Value'],
            [
                ['MERCURE_URL', $_ENV['MERCURE_URL'] ?? getenv('MERCURE_URL') ?: '(not set)'],
                ['MERCURE_PUBLIC_URL', $_ENV['MERCURE_PUBLIC_URL'] ?? getenv('MERCURE_PUBLIC_URL') ?: '(not set)'],
                ['MERCURE_JWT_SECRET', isset($_ENV['MERCURE_JWT_SECRET']) || getenv('MERCURE_JWT_SECRET') ? '(set)' : '(not set)'],
            ]
        );

        // Run Mercure test if requested
        if ($input->getOption('test')) {
            $io->section('Testing Mercure Connection');

            try {
                $topic = '/test/mercure-worker';
                $data = json_encode([
                    'message' => 'Test from mercure-worker',
                    'timestamp' => time()
                ]);

                $update = new \Symfony\Component\Mercure\Update($topic, $data, false);
                $io->writeln("Publishing to topic: <info>$topic</info>");

                $result = $this->hub->publish($update);

                $io->success("Mercure publish test passed! ID: $result");
            } catch (\Throwable $e) {
                $io->error([
                    'Mercure publish test FAILED!',
                    $e->getMessage(),
                ]);
                $io->writeln('<comment>Trace:</comment>');
                $io->writeln($e->getTraceAsString());
                return Command::FAILURE;
            }
        }

        // Start the messenger consumer
        $io->section('Starting Messenger Consumer');

        $queues = explode(',', $input->getOption('queues'));
        $queues = array_map('trim', $queues);

        $io->writeln('Consuming from queues: <info>' . implode(', ', $queues) . '</info>');

        $command = array_merge(
            ['php', 'bin/console', 'messenger:consume'],
            $queues,
            ['-vv']
        );

        $limit = (int) $input->getOption('limit');
        if ($limit > 0) {
            $command[] = '--limit=' . $limit;
            $io->writeln("Message limit: <info>$limit</info>");
        }

        $io->newLine();
        $io->writeln('<comment>Starting consumer... (Press Ctrl+C to stop)</comment>');
        $io->newLine();

        $process = new Process($command);
        $process->setTimeout(null);
        $process->setIdleTimeout(null);

        $process->run(function ($type, $buffer) use ($io) {
            if (Process::ERR === $type) {
                $io->writeln('<error>' . trim($buffer) . '</error>');
            } else {
                $io->writeln(trim($buffer));
            }
        });

        if (!$process->isSuccessful()) {
            $io->error('Consumer exited with error: ' . $process->getExitCode());
            return Command::FAILURE;
        }

        $io->success('Consumer finished successfully.');
        return Command::SUCCESS;
    }
}

