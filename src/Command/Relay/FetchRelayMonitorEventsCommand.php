<?php

declare(strict_types=1);

namespace App\Command\Relay;

use App\Message\FetchRelayMonitorEventsMessage;
use App\Repository\TrustedRelayMonitorRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Fetch NIP-66 events (10166 + 30166) for trusted relay monitors from external relays.
 *
 * Usage:
 *   # Fetch for a specific monitor
 *   bin/console relay:fetch-monitor-events 6d9717bc8758ddf99bc1b0e325d60bf5c41418dc122d81de6cd1a35138e51fe3
 *
 *   # Fetch for all trusted monitors
 *   bin/console relay:fetch-monitor-events --all
 */
#[AsCommand(
    name: 'relay:fetch-monitor-events',
    description: 'Fetch NIP-66 events (10166 + 30166) for trusted relay monitors from external relays',
)]
class FetchRelayMonitorEventsCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly TrustedRelayMonitorRepository $trustedRelayMonitorRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'pubkey',
                InputArgument::OPTIONAL,
                '64-char hex pubkey of the monitor to fetch events for',
            )
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Fetch events for all trusted monitors',
            )
            ->setHelp(
                'Dispatches a background job to fetch NIP-66 events (kinds 10166 and 30166) ' .
                'for one or all trusted relay monitors from the configured content relays.' . "\n\n" .
                'Events are processed through the GenericEventProjector, which calls ' .
                'RelayDiscoveryEventProjector to populate the relay_monitor and ' .
                'monitored_relay tables.' . "\n\n" .
                'Examples:' . "\n" .
                '  bin/console relay:fetch-monitor-events <pubkey>  # one monitor' . "\n" .
                '  bin/console relay:fetch-monitor-events --all     # all trusted monitors',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pubkey  = $input->getArgument('pubkey');
        $all     = $input->getOption('all');

        if (!$pubkey && !$all) {
            $io->error('Provide a pubkey argument or use --all to process every trusted monitor.');
            return Command::FAILURE;
        }

        if ($pubkey && $all) {
            $io->error('Provide either a pubkey argument or --all, not both.');
            return Command::FAILURE;
        }

        if ($pubkey) {
            if (strlen($pubkey) !== 64 || !ctype_xdigit($pubkey)) {
                $io->error('Invalid pubkey: must be a 64-character hex string.');
                return Command::FAILURE;
            }

            $this->dispatch($pubkey, $io);
            $io->success(sprintf('Dispatched fetch job for monitor %s…', substr($pubkey, 0, 16)));
            return Command::SUCCESS;
        }

        // --all: fetch for every trusted monitor
        $trusted = $this->trustedRelayMonitorRepository->findAll();

        if (empty($trusted)) {
            $io->warning('No trusted monitors in the database.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Dispatching fetch jobs for %d trusted monitor(s)…', count($trusted)));

        foreach ($trusted as $monitor) {
            $pk = $monitor->getPubkey();
            $this->dispatch($pk, $io);
            $io->writeln(sprintf('  → queued %s…', substr($pk, 0, 16)));
        }

        $io->success(sprintf('Dispatched %d fetch job(s). Workers will process them shortly.', count($trusted)));
        return Command::SUCCESS;
    }

    private function dispatch(string $pubkey, SymfonyStyle $io): void
    {
        try {
            $this->bus->dispatch(new FetchRelayMonitorEventsMessage($pubkey));
        } catch (\Throwable $e) {
            $io->warning(sprintf('Failed to dispatch for %s: %s', substr($pubkey, 0, 16), $e->getMessage()));
        }
    }
}

