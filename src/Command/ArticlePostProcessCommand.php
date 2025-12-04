<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'articles:post-process',
    description: 'Run post-processing commands (QA, index, indexed) on articles'
)]
class ArticlePostProcessCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption(
                'skip-qa',
                null,
                InputOption::VALUE_NONE,
                'Skip the QA step'
            )
            ->addOption(
                'skip-index',
                null,
                InputOption::VALUE_NONE,
                'Skip the ElasticSearch indexing step'
            )
            ->addOption(
                'skip-indexed',
                null,
                InputOption::VALUE_NONE,
                'Skip marking articles as indexed'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Article Post-Processing');
        $io->text('Running QA and indexing commands sequentially...');
        $io->newLine();

        $skipQa = $input->getOption('skip-qa');
        $skipIndex = $input->getOption('skip-index');
        $skipIndexed = $input->getOption('skip-indexed');

        $commands = [];
        if (!$skipQa) {
            $commands[] = [
                'name' => 'articles:qa',
                'description' => 'Quality Assurance',
            ];
        }
        if (!$skipIndex) {
            $commands[] = [
                'name' => 'articles:index',
                'description' => 'ElasticSearch Indexing',
            ];
        }
        if (!$skipIndexed) {
            $commands[] = [
                'name' => 'articles:indexed',
                'description' => 'Mark as Indexed',
            ];
        }

        if (empty($commands)) {
            $io->warning('All steps skipped - nothing to do!');
            return Command::SUCCESS;
        }

        foreach ($commands as $cmd) {
            $io->section(sprintf('Running: %s', $cmd['description']));

            try {
                // Create process to run the command
                $process = new Process([
                    PHP_BINARY,
                    'bin/console',
                    $cmd['name'],
                    '--no-interaction'
                ]);
                $process->setTimeout(600); // 10 minutes timeout

                // Run and stream output in real-time
                $process->run(function ($type, $buffer) use ($output) {
                    $output->write($buffer);
                });

                if (!$process->isSuccessful()) {
                    $io->error(sprintf(
                        '%s failed with exit code: %d',
                        $cmd['description'],
                        $process->getExitCode()
                    ));

                    $errorOutput = $process->getErrorOutput();
                    if ($errorOutput) {
                        $io->text('Error output:');
                        $io->text($errorOutput);
                    }

                    return Command::FAILURE;
                }

                $io->success(sprintf('✓ %s completed', $cmd['description']));
                $io->newLine();

            } catch (\Exception $e) {
                $io->error(sprintf('Failed to run %s: %s', $cmd['name'], $e->getMessage()));
                return Command::FAILURE;
            }
        }

        $io->success('✓ All post-processing commands completed successfully!');

        $io->newLine();
        $io->text([
            'Commands executed:',
            sprintf('  • articles:qa: %s', $skipQa ? 'skipped' : 'completed'),
            sprintf('  • articles:index: %s', $skipIndex ? 'skipped' : 'completed'),
            sprintf('  • articles:indexed: %s', $skipIndexed ? 'skipped' : 'completed'),
        ]);

        return Command::SUCCESS;
    }
}

