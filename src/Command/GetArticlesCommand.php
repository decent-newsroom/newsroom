<?php

namespace App\Command;

use App\Service\Nostr\NostrClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'articles:get',
    description: 'Pull articles from a default relay',
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
            ->addArgument('from', InputArgument::REQUIRED, 'From')
            ->addArgument('to', InputArgument::REQUIRED, 'To');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $from = strtotime($input->getArgument('from'));
        $to = strtotime($input->getArgument('to'));
        $this->nostrClient->getLongFormContent($from, $to);

        return Command::SUCCESS;
    }
}
