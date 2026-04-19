<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\FetchAuthorContentMessage;
use App\Message\RevalidateProfileCacheMessage;
use App\Service\Cache\RedisCacheService;
use App\Service\Cache\RedisViewStore;
use App\Util\NostrKeyUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'profile:regenerate',
    description: 'Invalidate and regenerate profile cache for a given npub or hex pubkey'
)]
class ProfileRegenerateCommand extends Command
{
    public function __construct(
        private readonly RedisViewStore $viewStore,
        private readonly RedisCacheService $redisCacheService,
        private readonly MessageBusInterface $messageBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('identifier', InputArgument::REQUIRED, 'npub or hex pubkey of the profile to regenerate')
            ->setHelp(
                'Invalidates all cached profile tabs and dispatches background revalidation.' . "\n\n" .
                'Examples:' . "\n" .
                '  php bin/console profile:regenerate npub1abc...' . "\n" .
                '  php bin/console profile:regenerate <hex-pubkey>'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = trim($input->getArgument('identifier'));

        // Resolve to hex pubkey
        if (NostrKeyUtil::isNpub($identifier)) {
            try {
                $pubkey = NostrKeyUtil::npubToHex($identifier);
            } catch (\InvalidArgumentException $e) {
                $io->error('Invalid npub: ' . $e->getMessage());
                return Command::FAILURE;
            }
        } elseif (NostrKeyUtil::isHexPubkey($identifier)) {
            $pubkey = $identifier;
        } else {
            $io->error('Identifier must be a valid npub or 64-character hex pubkey.');
            return Command::FAILURE;
        }

        $io->info(sprintf('Regenerating profile for pubkey: %s', $pubkey));

        // 1. Invalidate all cached profile tabs
        $this->viewStore->invalidateProfileTabs($pubkey);
        $io->writeln('  ✓ Invalidated cached profile tabs');

        // 2. Invalidate profile views (articles lists etc.)
        $this->redisCacheService->invalidateProfileViews($pubkey);
        $io->writeln('  ✓ Invalidated profile views');

        // 3. Dispatch revalidation messages for all tabs
        $tabs = ['overview', 'articles', 'media', 'highlights'];
        foreach ($tabs as $tab) {
            $this->messageBus->dispatch(new RevalidateProfileCacheMessage($pubkey, $tab, true));
        }
        $io->writeln(sprintf('  ✓ Dispatched revalidation for tabs: %s', implode(', ', $tabs)));

        $io->success('Profile cache invalidated and revalidation dispatched. The profile will rebuild in the background.');

        return Command::SUCCESS;
    }
}

