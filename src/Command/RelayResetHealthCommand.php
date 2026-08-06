<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Nostr\RelayHealthStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'relay:reset-health',
    description: 'Scrub all persisted relay health data and unmute every relay (fresh start)',
)]
class RelayResetHealthCommand extends Command
{
    public function __construct(
        private readonly RelayHealthStore $healthStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be cleared without making changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Reset Relay Health');

        $healthCount = count($this->healthStore->getAllKnownRelayUrls());
        $mutedCount = count($this->healthStore->getMutedRelays());

        $io->text([
            sprintf('Relay health records: <info>%d</info>', $healthCount),
            sprintf('Muted relays:         <info>%d</info>', $mutedCount),
        ]);
        $io->note('Filter statistics (relay_filter_stats:*) are NOT affected.');

        if ($dryRun) {
            $io->success('Dry-run — no changes were made.');
            return Command::SUCCESS;
        }

        if ($healthCount === 0 && $mutedCount === 0) {
            $io->success('Nothing to reset — no health data or muted relays found.');
            return Command::SUCCESS;
        }

        if (!$io->confirm('This will permanently delete all relay health data and unmute every relay. Continue?', false)) {
            $io->warning('Aborted — no changes were made.');
            return Command::SUCCESS;
        }

        $result = $this->healthStore->resetAllHealth();

        $io->success(sprintf(
            'Reset complete. Cleared %d health record(s) and unmuted %d relay(s).',
            $result['health_keys'],
            $result['muted'],
        ));

        return Command::SUCCESS;
    }
}
