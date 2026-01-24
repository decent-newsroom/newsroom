<?php

namespace App\Command;

use App\Service\Nostr\NostrRelayPool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'nostr:pool:cleanup',
    description: 'Clean up stale relay connections from the pool',
)]
class NostrRelayPoolCleanupCommand extends Command
{
    public function __construct(
        private readonly NostrRelayPool $relayPool
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('max-age', null, InputOption::VALUE_REQUIRED, 'Maximum age of connections in seconds', 300)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $maxAge = (int) $input->getOption('max-age');

        $io->info(sprintf('Cleaning up connections older than %d seconds...', $maxAge));

        $cleaned = $this->relayPool->cleanupStaleConnections($maxAge);

        if ($cleaned > 0) {
            $io->success(sprintf('Cleaned up %d stale connection(s).', $cleaned));
        } else {
            $io->info('No stale connections found.');
        }

        return Command::SUCCESS;
    }
}
