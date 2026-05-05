<?php

declare(strict_types=1);

namespace App\Command\Relay;

use App\Message\FetchRelayInformationMessage;
use App\Repository\RelayInformationRepository;
use App\Service\Nostr\RelayInformationFetcher;
use App\Service\Nostr\RelayRegistry;
use App\Util\RelayUrlNormalizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Refresh cached NIP-11 Relay Information Documents.
 *
 *   bin/console app:relays:refresh-information --all
 *   bin/console app:relays:refresh-information --url=wss://relay.damus.io
 *   bin/console app:relays:refresh-information --stale --max-age=21600
 *   bin/console app:relays:refresh-information --all --async
 */
#[AsCommand(
    name: 'app:relays:refresh-information',
    description: 'Fetch and cache NIP-11 Relay Information Documents.'
)]
class RefreshRelayInformationCommand extends Command
{
    private const DEFAULT_MAX_AGE = 21600; // 6 hours

    public function __construct(
        private readonly RelayRegistry $relayRegistry,
        private readonly RelayInformationRepository $repository,
        private readonly RelayInformationFetcher $fetcher,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Refresh a single relay URL')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Refresh every configured + previously-seen relay')
            ->addOption('stale', null, InputOption::VALUE_NONE, 'Only refresh entries older than --max-age')
            ->addOption('max-age', null, InputOption::VALUE_REQUIRED, 'Stale threshold in seconds', (string) self::DEFAULT_MAX_AGE)
            ->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch via Messenger instead of running inline');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $singleUrl = $input->getOption('url');
        if (is_string($singleUrl) && $singleUrl !== '') {
            return $this->executeRefresh([$singleUrl], (bool) $input->getOption('async'), $io);
        }

        $all   = (bool) $input->getOption('all');
        $stale = (bool) $input->getOption('stale');
        if (!$all && !$stale) {
            $io->error('Specify --url=, --all, or --stale.');
            return Command::INVALID;
        }

        $maxAge = max(60, (int) $input->getOption('max-age'));

        $urls = $this->collectUrls($all, $stale, $maxAge);
        if ($urls === []) {
            $io->success('Nothing to refresh.');
            return Command::SUCCESS;
        }

        return $this->executeRefresh($urls, (bool) $input->getOption('async'), $io);
    }

    /**
     * @return string[]
     */
    private function collectUrls(bool $all, bool $stale, int $maxAge): array
    {
        $byUrl = [];

        // Always include configured relays
        foreach ($this->relayRegistry->getAllUrls() as $url) {
            $byUrl[RelayUrlNormalizer::normalize($url)] = $url;
        }

        if ($all) {
            // Include every previously-seen relay, even if not in the registry
            foreach ($this->repository->findAll() as $row) {
                $byUrl[$row->getUrl()] = $row->getUrl();
            }
            return array_values($byUrl);
        }

        // --stale: keep only registered + previously-seen URLs that are old
        $staleRows = $this->repository->findStale($maxAge);
        $staleSet = [];
        foreach ($staleRows as $row) {
            $staleSet[$row->getUrl()] = true;
        }

        // Configured relays that have NEVER been fetched count as stale too
        $known = $this->repository->findManyIndexed(array_values($byUrl));
        $out = [];
        foreach ($byUrl as $normalized => $rawUrl) {
            if (!isset($known[$normalized]) || isset($staleSet[$normalized])) {
                $out[] = $rawUrl;
            }
        }
        // Plus any previously-seen-but-now-stale rows that aren't in the registry
        foreach ($staleSet as $normalized => $_) {
            if (!isset($byUrl[$normalized])) {
                $out[] = $normalized;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @param string[] $urls
     */
    private function executeRefresh(array $urls, bool $async, SymfonyStyle $io): int
    {
        $io->writeln(sprintf('Refreshing %d relay(s)%s...', count($urls), $async ? ' (async)' : ''));

        if ($async) {
            foreach ($urls as $url) {
                $this->bus->dispatch(new FetchRelayInformationMessage($url));
            }
            $io->success(sprintf('Dispatched %d FetchRelayInformationMessage(s).', count($urls)));
            return Command::SUCCESS;
        }

        $ok = 0;
        $err = 0;
        foreach ($urls as $url) {
            $entity = $this->fetcher->fetch($url);
            if ($entity->getFetchError() === null) {
                $ok++;
                $io->writeln(sprintf('  ✓ %s — %s %s', $url, $entity->getSoftware() ?? '?', $entity->getVersion() ?? ''));
            } else {
                $err++;
                $io->writeln(sprintf('  ✗ %s — %s', $url, $entity->getFetchError()));
            }
        }

        $io->success(sprintf('Done. ok=%d, errors=%d.', $ok, $err));
        return Command::SUCCESS;
    }
}

