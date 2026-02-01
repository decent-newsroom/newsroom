<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\AuthorContentType;
use App\Message\FetchAuthorContentMessage;
use App\Service\ActiveIndexingService;
use App\Service\AuthorRelayService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use swentel\nostr\Key\Key;

/**
 * Fetches content for all active indexing subscribers from their declared relays.
 * This command bypasses QA gates - content from active subscribers goes directly to indexing.
 */
#[AsCommand(
    name: 'active-indexing:fetch',
    description: 'Fetch content for active indexing subscribers from their declared relays'
)]
class ActiveIndexingFetchCommand extends Command
{
    public function __construct(
        private readonly ActiveIndexingService $activeIndexingService,
        private readonly AuthorRelayService $authorRelayService,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'since-hours',
                null,
                InputOption::VALUE_OPTIONAL,
                'Fetch content from the last N hours',
                '24'
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_OPTIONAL,
                'Limit number of subscriptions to process',
                null
            )
            ->addOption(
                'npub',
                null,
                InputOption::VALUE_OPTIONAL,
                'Process only a specific npub',
                null
            )
            ->setHelp(
                'This command fetches content for users with ROLE_ACTIVE_INDEXING from their ' .
                'declared relay list (NIP-65) or custom relays. Content from these users ' .
                'bypasses QA gates and is indexed directly.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sinceHours = (int) $input->getOption('since-hours');
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : null;
        $specificNpub = $input->getOption('npub');

        $io->title('Active Indexing Content Fetch');

        // Calculate since timestamp
        $since = (new \DateTime("-{$sinceHours} hours"))->getTimestamp();

        $subscriptions = [];

        if ($specificNpub) {
            $subscription = $this->activeIndexingService->getSubscription($specificNpub);
            if ($subscription && $subscription->isActive()) {
                $subscriptions = [$subscription];
            } else {
                $io->warning("No active subscription found for npub: {$specificNpub}");
                return Command::SUCCESS;
            }
        } else {
            $subscriptions = $this->activeIndexingService->getSubscriptionsNeedingFetch(60);
            if ($limit) {
                $subscriptions = array_slice($subscriptions, 0, $limit);
            }
        }

        if (empty($subscriptions)) {
            $io->info('No subscriptions need fetching at this time.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Processing %d active subscription(s)...', count($subscriptions)));
        $io->progressStart(count($subscriptions));

        $key = new Key();
        $totalArticles = 0;

        foreach ($subscriptions as $subscription) {
            $npub = $subscription->getNpub();

            try {
                // Convert npub to hex pubkey
                $pubkeyHex = $key->convertToHex($npub);

                // Determine which relays to use
                $relays = $subscription->getEffectiveRelays();

                if ($relays === null) {
                    // Use NIP-65 relay discovery
                    $relays = $this->authorRelayService->getRelaysForFetching($pubkeyHex);
                }

                if (empty($relays)) {
                    $this->logger->warning('No relays available for subscriber', [
                        'npub' => $npub,
                    ]);
                    $io->progressAdvance();
                    continue;
                }

                $this->logger->info('Fetching content for active subscriber', [
                    'npub' => substr($npub, 0, 20) . '...',
                    'relays' => array_slice($relays, 0, 3),
                    'since' => date('Y-m-d H:i:s', $since),
                ]);

                // Dispatch message to fetch all content types
                // Note: isOwner=true to fetch drafts if the subscriber wants them
                $this->messageBus->dispatch(new FetchAuthorContentMessage(
                    pubkey: $pubkeyHex,
                    contentTypes: AuthorContentType::publicTypes(),
                    since: $since,
                    isOwner: false, // Only public content for indexing
                    relays: $relays
                ));

                // Record the fetch
                $this->activeIndexingService->recordFetch($subscription);

            } catch (\Exception $e) {
                $this->logger->error('Failed to fetch content for subscriber', [
                    'npub' => $npub,
                    'error' => $e->getMessage(),
                ]);
            }

            $io->progressAdvance();
        }

        $io->progressFinish();
        $io->success(sprintf(
            'Dispatched fetch requests for %d active subscriber(s).',
            count($subscriptions)
        ));

        return Command::SUCCESS;
    }
}
