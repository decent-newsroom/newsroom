<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Event;
use App\Enum\ActiveIndexingStatus;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\UpdateProService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use swentel\nostr\Key\Key;

/**
 * Worker that monitors for zap receipts (kind 9735) that match pending Updates Pro
 * invoices. When a matching receipt is found, the subscription is activated.
 *
 * Active Indexing subscriptions are intentionally not handled here: they use plain
 * LNURL-pay invoices (no zap request) which never produce a kind-9735 receipt, so
 * they are activated manually via `active-indexing:activate` or the admin dashboard.
 */
#[AsCommand(
    name: 'active-indexing:check-receipts',
    description: 'Check for zap receipts matching pending Updates Pro invoices'
)]
class SubscriptionZapReceiptWorkerCommand extends Command
{
    private readonly string $recipientPubkeyHex;

    public function __construct(
        private readonly UpdateProService $notificationProService,
        private readonly EventRepository $eventRepository,
        private readonly LoggerInterface $logger,
        string $recipientPubkey,
    ) {
        parent::__construct();

        // Normalize and convert npub to hex if needed
        $recipientPubkey = strtolower(trim($recipientPubkey));
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
                'Updates Pro invoices. Run this frequently via cron (e.g., every 5 minutes).'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sinceMinutes = (int) $input->getOption('since-minutes');

        $io->title('Updates Pro Zap Receipt Worker');

        $pendingPro = $this->notificationProService->getPendingSubscriptions();

        if (empty($pendingPro)) {
            $io->info('No pending Updates Pro subscriptions to check.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Checking %d pending subscription(s)...', count($pendingPro)));

        // Build a map of bolt11 -> subscription for quick lookup
        $proInvoiceMap = [];
        foreach ($pendingPro as $sub) {
            $bolt11 = $sub->getPendingInvoiceBolt11();
            if ($bolt11) {
                $proInvoiceMap[strtolower($bolt11)] = $sub;
            }
        }

        if (empty($proInvoiceMap)) {
            $io->info('No pending invoices to match.');
            return Command::SUCCESS;
        }

        // Get recent zap receipts to the recipient pubkey
        $since = (new \DateTime("-{$sinceMinutes} minutes"))->getTimestamp();
        $zapReceipts = $this->findZapReceiptsForRecipient($this->recipientPubkeyHex, $since);

        $io->info(sprintf('Found %d recent zap receipt(s) to check.', count($zapReceipts)));

        $proActivated = 0;
        foreach ($zapReceipts as $receipt) {
            $bolt11FromReceipt = $this->extractBolt11FromReceipt($receipt);
            if (!$bolt11FromReceipt) {
                continue;
            }
            $bolt11Lower = strtolower($bolt11FromReceipt);
            if (isset($proInvoiceMap[$bolt11Lower])) {
                $sub = $proInvoiceMap[$bolt11Lower];
                if ($sub->getStatus() === ActiveIndexingStatus::PENDING) {
                    $this->notificationProService->activateSubscription($sub, $receipt->getId());
                    $io->success(sprintf('Activated Updates Pro for %s', $sub->getNpub()));
                } else {
                    $this->notificationProService->renewSubscription($sub, $receipt->getId());
                    $io->success(sprintf('Renewed Updates Pro for %s', $sub->getNpub()));
                }
                $proActivated++;
                unset($proInvoiceMap[$bolt11Lower]);
            }
        }

        if ($proActivated > 0) {
            $io->success(sprintf('Processed %d Updates Pro payment(s).', $proActivated));
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
