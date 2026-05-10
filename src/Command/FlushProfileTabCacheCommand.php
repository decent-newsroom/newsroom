<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Cache\RedisViewStore;
use App\Util\NostrKeyUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'profile:flush-cache',
    description: 'Flush Redis profile tab cache for one or all users. Useful when cached data is stale and async revalidation is stuck.',
)]
class FlushProfileTabCacheCommand extends Command
{
    public function __construct(
        private readonly RedisViewStore $viewStore,
        private readonly \Redis $redis,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('npub', InputArgument::OPTIONAL, 'npub or hex pubkey to flush cache for (omit to flush ALL profile tab caches)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Flush all profile tab caches (use with caution on large instances)')
            ->setHelp(
                'Flush Redis profile tab cache entries.' . "\n\n" .
                'Single user:' . "\n" .
                '  bin/console profile:flush-cache npub1xxxx...' . "\n\n" .
                'All users (rebuilds will be triggered on next page visit):' . "\n" .
                '  bin/console profile:flush-cache --all'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $npubInput = $input->getArgument('npub');
        $flushAll = (bool) $input->getOption('all');

        if ($flushAll) {
            return $this->flushAll($io);
        }

        if ($npubInput !== null) {
            return $this->flushForUser($io, $npubInput);
        }

        $io->error('Provide an npub/hex pubkey or use --all to flush all profile tab caches.');
        $io->text('Usage examples:');
        $io->text('  bin/console profile:flush-cache npub1xxxx...');
        $io->text('  bin/console profile:flush-cache --all');
        return Command::FAILURE;
    }

    private function flushForUser(SymfonyStyle $io, string $npubInput): int
    {
        try {
            $pubkeyHex = NostrKeyUtil::isHexPubkey($npubInput)
                ? $npubInput
                : NostrKeyUtil::npubToHex($npubInput);
        } catch (\Throwable $e) {
            $io->error('Could not resolve to a hex pubkey: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->info(sprintf('Flushing profile tab cache for pubkey: %s', $pubkeyHex));

        $this->viewStore->invalidateProfileTabs($pubkeyHex);

        // Also clear the user articles view key for good measure
        $this->redis->del(sprintf('view:user:articles:%s', $pubkeyHex));

        $io->success(sprintf('Profile tab cache flushed for %s. Cache will be rebuilt on next page visit.', substr($pubkeyHex, 0, 16) . '...'));

        return Command::SUCCESS;
    }

    private function flushAll(SymfonyStyle $io): int
    {
        $io->caution('Flushing ALL profile tab caches. This will cause DB queries on next visit for every profile.');

        $deleted = 0;
        $patterns = [
            'view:profile:tab:*',
            'view:user:articles:*',
        ];

        try {
            foreach ($patterns as $pattern) {
                $iterator = null;

                // phpredis expects the iterator variable by reference.
                while (($keys = $this->redis->scan($iterator, $pattern, 100)) !== false) {
                    if (!empty($keys)) {
                        $this->redis->del(...$keys);
                        $deleted += count($keys);
                    }
                }
            }
        } catch (\Throwable $e) {
            $io->error('Failed to flush cache: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('Flushed %d profile tab cache keys. Caches will be rebuilt on next page visit.', $deleted));

        return Command::SUCCESS;
    }
}
