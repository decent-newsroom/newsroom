<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ActiveIndexingService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manually activate a subscription after verifying payment.
 * Use this command after confirming the user has paid their invoice.
 */
#[AsCommand(
    name: 'active-indexing:activate',
    description: 'Manually activate a subscription after payment verification'
)]
class ActivateSubscriptionCommand extends Command
{
    public function __construct(
        private readonly ActiveIndexingService $activeIndexingService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('npub', InputArgument::REQUIRED, 'The npub of the user to activate')
            ->setHelp(
                'This command manually activates a subscription after you have verified the payment.' . "\n" .
                'Use this after confirming the user has paid their Lightning invoice.' . "\n\n" .
                'Example:' . "\n" .
                '  php bin/console active-indexing:activate npub1...'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $npub = $input->getArgument('npub');

        $io->title('Manual Subscription Activation');

        $subscription = $this->activeIndexingService->getSubscription($npub);

        if (!$subscription) {
            $io->error("No subscription found for npub: {$npub}");
            return Command::FAILURE;
        }

        if ($subscription->isActive()) {
            $io->warning('This subscription is already active.');
            $io->table(
                ['Field', 'Value'],
                [
                    ['Status', $subscription->getStatus()->getLabel()],
                    ['Tier', $subscription->getTier()->getLabel()],
                    ['Expires', $subscription->getExpiresAt()?->format('Y-m-d H:i:s')],
                    ['Days Remaining', $subscription->getDaysRemaining()],
                ]
            );
            return Command::SUCCESS;
        }

        // Show subscription details
        $io->section('Subscription Details');
        $io->table(
            ['Field', 'Value'],
            [
                ['Npub', $npub],
                ['Current Status', $subscription->getStatus()->getLabel()],
                ['Tier', $subscription->getTier()->getLabel()],
                ['Amount', $subscription->getTier()->getPriceInSats() . ' sats'],
                ['Created', $subscription->getCreatedAt()->format('Y-m-d H:i:s')],
            ]
        );

        if ($subscription->getPendingInvoiceBolt11()) {
            $invoice = $subscription->getPendingInvoiceBolt11();
            $io->text('Pending Invoice: ' . substr($invoice, 0, 50) . '...');
        }

        $io->newLine();

        if (!$io->confirm('Have you verified that this user has paid the invoice?', false)) {
            $io->info('Activation cancelled.');
            return Command::SUCCESS;
        }

        try {
            $this->activeIndexingService->activateSubscription($subscription, 'manual-activation');

            $io->success('Subscription activated successfully!');
            $io->table(
                ['Field', 'Value'],
                [
                    ['Status', $subscription->getStatus()->getLabel()],
                    ['Started', $subscription->getStartedAt()?->format('Y-m-d H:i:s')],
                    ['Expires', $subscription->getExpiresAt()?->format('Y-m-d H:i:s')],
                    ['Grace Ends', $subscription->getGraceEndsAt()?->format('Y-m-d H:i:s')],
                ]
            );

            $io->text('The user now has ROLE_ACTIVE_INDEXING and their content will be fetched from their relays.');

        } catch (\Exception $e) {
            $io->error('Failed to activate subscription: ' . $e->getMessage());
            $this->logger->error('Manual subscription activation failed', [
                'npub' => $npub,
                'error' => $e->getMessage(),
            ]);
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
