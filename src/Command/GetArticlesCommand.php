<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Nostr\NostrClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'articles:get',
    description: 'Pull longform articles from the default relay for a date range',
)]
class GetArticlesCommand extends Command
{
    public function __construct(private readonly NostrClient $nostrClient)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('from', InputArgument::REQUIRED, 'Start date (e.g. "2025-01-01" or "-7 days")')
            ->addArgument('to', InputArgument::REQUIRED, 'End date (e.g. "2025-01-31" or "now")');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $from = strtotime($input->getArgument('from'));
        $to = strtotime($input->getArgument('to'));

        if ($from === false) {
            $io->error(sprintf('Invalid "from" date: %s', $input->getArgument('from')));
            return Command::INVALID;
        }

        if ($to === false) {
            $io->error(sprintf('Invalid "to" date: %s', $input->getArgument('to')));
            return Command::INVALID;
        }

        if ($from > $to) {
            $io->error('"from" date must be before "to" date.');
            return Command::INVALID;
        }

        $io->info(sprintf('Fetching longform articles from %s to %s...', date('Y-m-d H:i', $from), date('Y-m-d H:i', $to)));

        $this->nostrClient->getLongFormContent($from, $to);

        $io->success('Fetch complete.');

        return Command::SUCCESS;
    }
}
