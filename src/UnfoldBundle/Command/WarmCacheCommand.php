<?php

namespace App\UnfoldBundle\Command;

use App\Repository\UnfoldSiteRepository;
use App\UnfoldBundle\Cache\SiteConfigCacheWarmer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'unfold:cache:warm',
    description: 'Warm the SiteConfig cache for all configured UnfoldSites',
)]
class WarmCacheCommand extends Command
{
    public function __construct(
        private readonly UnfoldSiteRepository $unfoldSiteRepository,
        private readonly SiteConfigCacheWarmer $cacheWarmer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('subdomain', 's', InputOption::VALUE_OPTIONAL, 'Warm cache for a specific subdomain only')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $subdomain = $input->getOption('subdomain');

        if ($subdomain) {
            $site = $this->unfoldSiteRepository->findBySubdomain($subdomain);

            if ($site === null) {
                $io->error(sprintf('UnfoldSite not found for subdomain: %s', $subdomain));
                return Command::FAILURE;
            }

            $io->info(sprintf('Warming cache for subdomain: %s', $subdomain));

            if ($this->cacheWarmer->warmSite($site)) {
                $io->success('Cache warmed successfully!');
                return Command::SUCCESS;
            } else {
                $io->error('Failed to warm cache. Check logs for details.');
                return Command::FAILURE;
            }
        }

        // Warm all sites
        $sites = $this->unfoldSiteRepository->findAll();
        $count = count($sites);

        if ($count === 0) {
            $io->warning('No UnfoldSites configured.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Warming cache for %d site(s)...', $count));

        $results = $this->cacheWarmer->warmAll($sites);

        if ($results['failed'] === 0) {
            $io->success(sprintf('All %d site(s) cached successfully!', $results['success']));
            return Command::SUCCESS;
        }

        $io->warning(sprintf(
            'Cached %d site(s), %d failed. Check logs for details.',
            $results['success'],
            $results['failed']
        ));

        return $results['success'] > 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
