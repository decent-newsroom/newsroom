<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Event;
use App\Enum\ActiveIndexingStatus;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\ActiveIndexingService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use swentel\nostr\Key\Key;

/**
 * Worker that monitors for zap receipts (kind 9735) that match pending subscription invoices.
 * When a matching receipt is found, the subscription is activated.
 */
#[AsCommand(
    name: 'active-indexing:check-receipts',
    description: 'Check for zap receipts matching pending subscription invoices'
)]
class SubscriptionZapReceiptWorkerCommand extends Command
{
    private readonly string $recipientPubkeyHex;

    public function __construct(
        private readonly ActiveIndexingService $activeIndexingService,
        private readonly EventRepository $eventRepository,
        private readonly LoggerInterface $logger,
        string $recipientPubkey,
    ) {
        parent::__construct();

        // Convert npub to hex if needed
        if (str_starts_with($recipientPubkey, 'npub1')) {
            $key = new Key();
            $this->recipientPubkeyHex = $key->convertToHex($recipientPubkey);
        } else {
            $this->recipientPubkeyHex = $recipientPubkey;
        }
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'since-minutes',
                null,
                InputOption::VALUE_OPTIONAL,
                'Check receipts from the last N minutes',
                '30'
            )
            ->setHelp(
                'This command checks for zap receipt events (kind 9735) that match pending ' .
                'subscription invoices. Run this frequently via cron (e.g., every 5 minutes).'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sinceMinutes = (int) $input->getOption('since-minutes');

        $io->title('Subscription Zap Receipt Worker');

        // Get pending subscriptions
        $pendingSubscriptions = $this->activeIndexingService->getPendingSubscriptions();

        if (empty($pendingSubscriptions)) {
            $io->info('No pending subscriptions to check.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Checking %d pending subscription(s)...', count($pendingSubscriptions)));

        // Build a map of bolt11 -> subscription for quick lookup
        $invoiceMap = [];
        foreach ($pendingSubscriptions as $subscription) {
            $bolt11 = $subscription->getPendingInvoiceBolt11();
            if ($bolt11) {
                // Store lowercase for comparison
                $invoiceMap[strtolower($bolt11)] = $subscription;
            }
        }

        if (empty($invoiceMap)) {
            $io->info('No pending invoices to match.');
            return Command::SUCCESS;
        }

        // Get recent zap receipts to DN's pubkey
        $since = (new \DateTime("-{$sinceMinutes} minutes"))->getTimestamp();
        $zapReceipts = $this->findZapReceiptsForRecipient($this->recipientPubkeyHex, $since);

        $io->info(sprintf('Found %d recent zap receipt(s) to check.', count($zapReceipts)));

        $activatedCount = 0;

        foreach ($zapReceipts as $receipt) {
            // Extract bolt11 from the receipt
            $bolt11FromReceipt = $this->extractBolt11FromReceipt($receipt);

            if (!$bolt11FromReceipt) {
                continue;
            }

            $bolt11Lower = strtolower($bolt11FromReceipt);

            if (isset($invoiceMap[$bolt11Lower])) {
                $subscription = $invoiceMap[$bolt11Lower];

                // Check if this is a renewal or new activation
                if ($subscription->getStatus() === ActiveIndexingStatus::PENDING) {
                    $this->activeIndexingService->activateSubscription($subscription, $receipt->getId());
                    $io->success(sprintf('Activated subscription for %s', $subscription->getNpub()));
                } else {
                    // It's a renewal
                    $this->activeIndexingService->renewSubscription($subscription, $receipt->getId());
                    $io->success(sprintf('Renewed subscription for %s', $subscription->getNpub()));
                }

                $activatedCount++;

                // Remove from map to avoid processing again
                unset($invoiceMap[$bolt11Lower]);
            }
        }

        if ($activatedCount > 0) {
            $io->success(sprintf('Processed %d subscription payment(s).', $activatedCount));
        } else {
            $io->info('No matching payments found.');
        }

        return Command::SUCCESS;
    }

    /**
     * Find zap receipt events for a specific recipient pubkey
     * @return Event[]
     */
    private function findZapReceiptsForRecipient(string $recipientPubkey, int $since): array
    {
        // Query for kind 9735 events with 'p' tag matching recipient
        return $this->eventRepository->createQueryBuilder('e')
            ->where('e.kind = :kind')
            ->andWhere('e.created_at >= :since')
            ->setParameter('kind', KindsEnum::ZAP_RECEIPT->value)
            ->setParameter('since', $since)
            ->orderBy('e.created_at', 'DESC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();
    }

    /**
     * Extract bolt11 invoice from a zap receipt event.
     * The bolt11 is typically in the 'bolt11' tag or embedded in the description tag.
     */
    private function extractBolt11FromReceipt(Event $receipt): ?string
    {
        $tags = $receipt->getTags();

        foreach ($tags as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }

            // Direct bolt11 tag
            if ($tag[0] === 'bolt11') {
                return $tag[1];
            }

            // Description tag may contain the zap request with bolt11
            if ($tag[0] === 'description') {
                $descriptionJson = $tag[1];
                try {
                    $description = json_decode($descriptionJson, true);
                    // The description is the zap request event, look for bolt11 in its tags
                    if (isset($description['tags'])) {
                        foreach ($description['tags'] as $descTag) {
                            if (is_array($descTag) && ($descTag[0] ?? '') === 'bolt11') {
                                return $descTag[1] ?? null;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Not valid JSON, skip
                }
            }
        }

        return null;
    }
}
