<?php

namespace App\Command;

use App\Service\NostrRelayPool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'nostr:pool:stats',
    description: 'Display statistics about the Nostr relay connection pool',
)]
class NostrRelayPoolStatsCommand extends Command
{
    public function __construct(
        private readonly NostrRelayPool $relayPool
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $stats = $this->relayPool->getStats();

        $io->title('Nostr Relay Pool Statistics');

        $io->section('Overview');
        $io->table(
            ['Metric', 'Value'],
            [
                ['Active Connections', $stats['active_connections']],
            ]
        );

        if (!empty($stats['relays'])) {
            $io->section('Relay Details');
            $rows = [];
            foreach ($stats['relays'] as $relay) {
                $rows[] = [
                    $relay['url'],
                    $relay['attempts'],
                    $relay['last_connected'] ? date('Y-m-d H:i:s', $relay['last_connected']) : 'Never',
                    $relay['age'] . 's',
                ];
            }
            $io->table(
                ['Relay URL', 'Failed Attempts', 'Last Connected', 'Age'],
                $rows
            );
        } else {
            $io->info('No active relay connections.');
        }

        return Command::SUCCESS;
    }
}

