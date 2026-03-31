<?php

namespace App\Command;

use App\Service\Nostr\NostrRelayPool;
use App\Service\Nostr\RelayHealthStore;
use App\Service\Nostr\RelayRegistry;
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
        private readonly NostrRelayPool $relayPool,
        private readonly RelayRegistry $relayRegistry,
        private readonly RelayHealthStore $healthStore,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $stats = $this->relayPool->getStats();
        $localRelay = $this->relayRegistry->getLocalRelay();
        $defaultRelays = $this->relayPool->getDefaultRelays();

        $io->title('Nostr Relay Pool Statistics');

        // ---- Registry ----
        $io->section('Relay Registry');
        $all = $this->relayRegistry->getAll();
        foreach ($all as $purpose => $urls) {
            if (empty($urls)) {
                continue;
            }
            $io->text(sprintf('<info>%s</info>: %s', ucfirst($purpose), implode(', ', $urls)));
        }

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

        // ---- Health Store (persistent, Redis-backed) ----
        $allUrls = $this->relayRegistry->getAllUrls();
        $healthData = $this->healthStore->getHealthForRelays($allUrls);
        $hasHealth = false;
        foreach ($healthData as $data) {
            if ($data['last_success'] || $data['last_failure']) {
                $hasHealth = true;
                break;
            }
        }

        if ($hasHealth) {
            $io->section('Persistent Health (Redis)');
            $rows = [];
            foreach ($healthData as $url => $h) {
                if (!$h['last_success'] && !$h['last_failure']) {
                    continue;
                }
                $heartbeats = [];
                foreach ($h['heartbeats'] as $worker => $ts) {
                    $heartbeats[] = $worker . ':' . (time() - $ts) . 's ago';
                }
                $rows[] = [
                    $url,
                    sprintf('%.2f', $this->healthStore->getHealthScore($url)),
                    $h['consecutive_failures'],
                    $h['avg_latency_ms'] !== null ? round($h['avg_latency_ms']) . 'ms' : '-',
                    $h['last_success'] ? date('H:i:s', $h['last_success']) : '-',
                    $h['last_failure'] ? date('H:i:s', $h['last_failure']) : '-',
                    $h['auth_required'] ? 'yes (' . $h['auth_status'] . ')' : 'no',
                    $h['last_event_received'] ? (time() - $h['last_event_received']) . 's ago' : '-',
                    implode(', ', $heartbeats) ?: '-',
                ];
            }
            $io->table(
                ['Relay', 'Score', 'Failures', 'Latency', 'Last OK', 'Last Fail', 'Auth', 'Last Event', 'Heartbeats'],
                $rows
            );
        } else {
            $io->info('No persistent health data yet (relays need to be contacted first).');
        }

        return Command::SUCCESS;
    }
}
