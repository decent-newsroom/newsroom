<?php

namespace App\UnfoldBundle\Command;

use App\UnfoldBundle\Config\SiteConfigLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'unfold:test:fetch',
    description: 'Test fetching a magazine by coordinate (for debugging)',
)]
class TestFetchCommand extends Command
{
    public function __construct(
        private readonly SiteConfigLoader $siteConfigLoader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('coordinate', InputArgument::REQUIRED, 'Magazine coordinate (e.g., 30040:pubkey:slug)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $coordinate = $input->getArgument('coordinate');

        $io->title('Testing magazine fetch');
        $io->info(sprintf('Coordinate: %s', $coordinate));

        try {
            $io->section('Attempting to load SiteConfig...');
            $siteConfig = $this->siteConfigLoader->loadFromCoordinate($coordinate);

            if ($siteConfig->title === 'Loading...') {
                $io->warning('Got placeholder config - fetch failed or returned placeholder');
                $io->table(
                    ['Field', 'Value'],
                    [
                        ['Title', $siteConfig->title],
                        ['Description', $siteConfig->description],
                        ['Pubkey', $siteConfig->pubkey ?: '(empty)'],
                        ['Categories', count($siteConfig->categories)],
                        ['Theme', $siteConfig->theme],
                    ]
                );
                return Command::FAILURE;
            }

            $io->success('Successfully fetched magazine!');
            $io->table(
                ['Field', 'Value'],
                [
                    ['Title', $siteConfig->title],
                    ['Description', substr($siteConfig->description, 0, 100) . '...'],
                    ['Pubkey', substr($siteConfig->pubkey, 0, 16) . '...'],
                    ['Categories', count($siteConfig->categories)],
                    ['Theme', $siteConfig->theme],
                ]
            );

            if (!empty($siteConfig->categories)) {
                $io->section('Categories:');
                foreach ($siteConfig->categories as $cat) {
                    $io->writeln(sprintf('  - %s', $cat));
                }
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(sprintf('Failed to fetch: %s', $e->getMessage()));
            $io->block($e->getTraceAsString(), null, 'fg=white;bg=red', ' ', true);
            return Command::FAILURE;
        }
    }
}

