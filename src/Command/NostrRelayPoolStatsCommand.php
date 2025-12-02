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
        $localRelay = $this->relayPool->getLocalRelay();
        $defaultRelays = $this->relayPool->getDefaultRelays();

        $io->title('Nostr Relay Pool Statistics');

        $io->section('Configuration');
        $io->table(
            ['Setting', 'Value'],
            [
                ['Local Relay', $localRelay ?: '(not configured)'],
                ['Default Relays', count($defaultRelays)],
            ]
        );

        if (!empty($defaultRelays)) {
            $io->section('Default Relay List (Priority Order)');
            $rows = [];
            foreach ($defaultRelays as $index => $relay) {
                $isLocal = $localRelay && $relay === $localRelay;
                $rows[] = [
                    $index + 1,
                    $relay,
                    $isLocal ? '✓ Local' : 'Public'
                ];
            }
            $io->table(['Priority', 'Relay URL', 'Type'], $rows);
        }

        $io->section('Connection Pool');
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

