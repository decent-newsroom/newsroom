<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ActiveIndexingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * List all subscriptions and their statuses
 */
#[AsCommand(
    name: 'active-indexing:list',
    description: 'List all active indexing subscriptions'
)]
class ListSubscriptionsCommand extends Command
{
    public function __construct(
        private readonly ActiveIndexingService $activeIndexingService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Active Indexing Subscriptions');

        // Get statistics
        $stats = $this->activeIndexingService->getStatistics();

        $io->section('Summary');
        $io->table(
            ['Metric', 'Count'],
            [
                ['Total Subscriptions', $stats['total']],
                ['Active', $stats['active']],
                ['Grace Period', $stats['grace']],
                ['Pending Payment', $stats['pending']],
                ['Total Articles Indexed', $stats['total_articles_indexed']],
            ]
        );

        // List pending subscriptions
        $pending = $this->activeIndexingService->getPendingSubscriptions();
        if (!empty($pending)) {
            $io->section('Pending Subscriptions (awaiting payment)');
            $rows = [];
            foreach ($pending as $sub) {
                $rows[] = [
                    substr($sub->getNpub(), 0, 20) . '...',
                    $sub->getTier()->getLabel(),
                    $sub->getTier()->getPriceInSats() . ' sats',
                    $sub->getCreatedAt()->format('Y-m-d H:i'),
                ];
            }
            $io->table(['Npub', 'Tier', 'Amount', 'Created'], $rows);
            $io->note('Use: php bin/console active-indexing:activate <npub> to manually activate after payment');
        }

        // List active subscriptions
        $active = $this->activeIndexingService->getActiveSubscriptions();
        if (!empty($active)) {
            $io->section('Active Subscriptions');
            $rows = [];
            foreach ($active as $sub) {
                $rows[] = [
                    substr($sub->getNpub(), 0, 20) . '...',
                    $sub->getStatus()->getLabel(),
                    $sub->getTier()->value,
                    $sub->getExpiresAt()?->format('Y-m-d'),
                    $sub->getDaysRemaining() ?? 'N/A',
                    $sub->getArticlesIndexed(),
                    $sub->getLastFetchedAt()?->format('Y-m-d H:i') ?? 'Never',
                ];
            }
            $io->table(
                ['Npub', 'Status', 'Tier', 'Expires', 'Days Left', 'Articles', 'Last Fetch'],
                $rows
            );
        }

        $io->success('Subscription list complete.');

        return Command::SUCCESS;
    }
}
