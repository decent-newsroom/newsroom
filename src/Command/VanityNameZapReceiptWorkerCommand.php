<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Enum\VanityNameStatus;
use App\Repository\EventRepository;
use App\Repository\VanityNameRepository;
use App\Service\VanityNameService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Worker that monitors for zap receipts (kind 9735) that match pending vanity name invoices.
 * When a matching receipt is found, the vanity name is activated.
 */
#[AsCommand(
    name: 'vanity:check-receipts',
    description: 'Check for zap receipts matching pending vanity name invoices'
)]
class VanityNameZapReceiptWorkerCommand extends Command
{
    public function __construct(
        private readonly VanityNameService $vanityNameService,
        private readonly VanityNameRepository $vanityNameRepository,
        private readonly EventRepository $eventRepository,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
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
                'vanity name invoices. Run this frequently via cron (e.g., every 5 minutes).'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sinceMinutes = (int) $input->getOption('since-minutes');

        $io->title('Vanity Name Zap Receipt Worker');

        // Get pending vanity names
        $pendingVanityNames = $this->vanityNameRepository->findPending();

        if (empty($pendingVanityNames)) {
            $io->info('No pending vanity names to check.');
            return Command::SUCCESS;
        }

        $io->info(sprintf('Checking %d pending vanity name(s)...', count($pendingVanityNames)));

        // Build a map of bolt11 -> vanityName for quick lookup
        $invoiceMap = [];
        foreach ($pendingVanityNames as $vanityName) {
            $bolt11 = $vanityName->getPendingInvoiceBolt11();
            if ($bolt11) {
                // Store lowercase for comparison
                $invoiceMap[strtolower($bolt11)] = $vanityName;
            }
        }

        if (empty($invoiceMap)) {
            $io->info('No pending invoices to match.');
            return Command::SUCCESS;
        }

        // Get recent zap receipts
        $since = (new \DateTime("-{$sinceMinutes} minutes"))->getTimestamp();
        $zapReceipts = $this->findRecentZapReceipts($since);

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
                $vanityName = $invoiceMap[$bolt11Lower];

                // Check if pending (new activation)
                if ($vanityName->getStatus() === VanityNameStatus::PENDING) {
                    $this->vanityNameService->activate($vanityName);
                    $io->success(sprintf(
                        'Activated vanity name "%s" for %s',
                        $vanityName->getVanityName(),
                        $vanityName->getNpub()
                    ));
                } else {
                    // Renewal
                    $this->vanityNameService->renew($vanityName);
                    $io->success(sprintf(
                        'Renewed vanity name "%s" for %s',
                        $vanityName->getVanityName(),
                        $vanityName->getNpub()
                    ));
                }

                $activatedCount++;

                // Remove from map to avoid processing again
                unset($invoiceMap[$bolt11Lower]);
            }
        }

        if ($activatedCount > 0) {
            $io->success(sprintf('Processed %d vanity name payment(s).', $activatedCount));
        } else {
            $io->info('No matching payments found.');
        }

        return Command::SUCCESS;
    }

    /**
     * Find recent zap receipt events
     * @return Event[]
     */
    private function findRecentZapReceipts(int $since): array
    {
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

