<?php

namespace DecentNewsroom\UnfoldBundle\Command;

use DecentNewsroom\UnfoldBundle\Cache\SiteConfigCacheWarmer;
use DecentNewsroom\UnfoldBundle\Contract\SiteRegistryInterface;
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
        private readonly SiteRegistryInterface $siteRegistry,
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
            $site = $this->siteRegistry->findBySubdomain($subdomain);

            if ($site === null) {
                $io->error(sprintf('UnfoldSite not found for subdomain: %s', $subdomain));
                return Command::FAILURE;
            }

            $io->info(sprintf('Warming cache for subdomain: %s', $subdomain));

            if ($this->cacheWarmer->warmPublicationSite($site)) {
                $io->success('Cache warmed successfully!');
                return Command::SUCCESS;
            } else {
                $io->error('Failed to warm cache. Check logs for details.');
                return Command::FAILURE;
            }
        }

        // Warm all sites
        $sites = $this->siteRegistry->findAll();
        $sites = is_array($sites) ? $sites : iterator_to_array($sites, false);
        $count = count($sites);

        if ($count === 0) {
            $io->warning('No UnfoldSites configured.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Warming cache for %d site(s)...', $count));

        $results = $this->cacheWarmer->warmAllPublicationSites($sites);

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
