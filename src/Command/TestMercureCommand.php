<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

#[AsCommand(
    name: 'app:test-mercure',
    description: 'Test Mercure publishing'
)]
class TestMercureCommand extends Command
{
    public function __construct(
        private readonly HubInterface $hub
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Testing Mercure publish...');

        try {
            $topic = '/test/ping';
            $data = json_encode([
                'message' => 'Test from CLI',
                'timestamp' => time()
            ]);

            $update = new Update($topic, $data, false);
            $output->writeln("Publishing to topic: $topic");
            $output->writeln("Data: $data");

            $result = $this->hub->publish($update);

            $output->writeln("<info>Published successfully! ID: $result</info>");
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln("<error>Error: " . $e->getMessage() . "</error>");
            $output->writeln("<error>Trace: " . $e->getTraceAsString() . "</error>");
            return Command::FAILURE;
        }
    }
}

