<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Nostr\RelayFilterStatsStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Show what filter shapes the gateway sends to relays and how long
 * each one takes to resolve. Answers: "what are my typical filters,
 * and which take long to come back?"
 */
#[AsCommand(
    name: 'relay:filter-stats',
    description: 'Display per-filter REQ statistics (counts and resolution latency).',
)]
class RelayFilterStatsCommand extends Command
{
    public function __construct(
        private readonly RelayFilterStatsStore $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('relay', null, InputOption::VALUE_REQUIRED, 'Show per-relay breakdown for this URL')
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'How many rows to show', '20')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'Sort by: count | avg | max | timeout', 'count')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Clear all filter stats and exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('clear')) {
            $this->store->clear();
            $io->success('Filter stats cleared.');
            return Command::SUCCESS;
        }

        $top = max(1, (int) $input->getOption('top'));
        $sort = $input->getOption('sort');
        $relay = $input->getOption('relay');

        if ($relay !== null) {
            return $this->renderRelay($io, (string) $relay, $top, $sort);
        }
        return $this->renderGlobal($io, $top, $sort);
    }

    private function renderGlobal(SymfonyStyle $io, int $top, string $sort): int
    {
        $rows = $this->store->getGlobalStats();
        if ($rows === []) {
            $io->warning('No filter stats recorded yet. The gateway populates these as it sends REQs.');
            $io->writeln('Make sure the relay-gateway service is running with --profile gateway.');
            return Command::SUCCESS;
        }

        $rows = $this->sort($rows, $sort);
        $rows = array_slice($rows, 0, $top);

        $io->title(sprintf('Top %d filter shapes (global, sorted by %s)', count($rows), $sort));
        $io->writeln(sprintf(
            'Tracking %d relays. Showing aggregate counts and weighted-average resolution latency.',
            count($this->store->getKnownRelays()),
        ));
        $io->writeln('');

        $table = [];
        foreach ($rows as $r) {
            $table[] = [
                'signature'  => $this->wrap($r['signature']),
                'reqs'       => number_format($r['count']),
                'eose'       => number_format($r['eose_count']),
                'timeouts'   => number_format($r['timeout_count']),
                'events'     => number_format($r['events']),
                'avg ms'     => $r['avg_ms'] !== null ? number_format($r['avg_ms'], 1) : '—',
                'max ms'     => $r['max_ms'] !== null ? number_format($r['max_ms']) : '—',
                'relays'     => (string) $r['relays'],
            ];
        }
        $io->table(
            ['signature', 'reqs', 'eose', 'timeouts', 'events', 'avg ms', 'max ms', 'relays'],
            $table,
        );

        $io->writeln('');
        $io->writeln('Use --relay=wss://… to see per-relay breakdown for one relay.');
        return Command::SUCCESS;
    }

    private function renderRelay(SymfonyStyle $io, string $relayUrl, int $top, string $sort): int
    {
        $rows = $this->store->getStatsForRelay($relayUrl);
        if ($rows === []) {
            $io->warning(sprintf('No filter stats for %s', $relayUrl));
            return Command::SUCCESS;
        }

        $rows = $this->sort($rows, $sort);
        $rows = array_slice($rows, 0, $top);

        $io->title(sprintf('Filter shapes sent to %s', $relayUrl));

        $table = [];
        foreach ($rows as $r) {
            $table[] = [
                'signature' => $this->wrap($r['signature']),
                'reqs'      => number_format($r['count']),
                'eose'      => number_format($r['eose_count']),
                'timeouts'  => number_format($r['timeout_count']),
                'events'    => number_format($r['events']),
                'avg ms'    => $r['avg_ms'] !== null ? number_format($r['avg_ms'], 1) : '—',
                'max ms'    => $r['max_ms'] !== null ? number_format($r['max_ms']) : '—',
                'last ms'   => $r['last_ms'] !== null ? number_format($r['last_ms']) : '—',
                'last seen' => $r['last_at'] !== null ? date('Y-m-d H:i:s', $r['last_at']) : '—',
            ];
        }
        $io->table(
            ['signature', 'reqs', 'eose', 'timeouts', 'events', 'avg ms', 'max ms', 'last ms', 'last seen'],
            $table,
        );
        return Command::SUCCESS;
    }

    /** @param list<array<string,mixed>> $rows */
    private function sort(array $rows, string $sort): array
    {
        usort($rows, function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'avg'     => ($b['avg_ms'] ?? 0) <=> ($a['avg_ms'] ?? 0),
                'max'     => ($b['max_ms'] ?? 0) <=> ($a['max_ms'] ?? 0),
                'timeout' => $b['timeout_count'] <=> $a['timeout_count'],
                default   => $b['count'] <=> $a['count'],
            };
        });
        return $rows;
    }

    private function wrap(string $signature, int $width = 60): string
    {
        return strlen($signature) > $width
            ? wordwrap($signature, $width, "\n", true)
            : $signature;
    }
}

