<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\EssayistZapClaim;
use App\Repository\EssayistZapClaimRepository;
use App\Service\Essayist\EssayistZapClaimService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manage pending Essayist zap claims (review, approve, reject).
 *
 * Examples:
 *   bin/console essayist:review-zap-claims        # List all pending claims
 *   bin/console essayist:review-zap-claims 5      # Approve claim ID 5
 *   bin/console essayist:review-zap-claims 5 --reject="Invalid amount"
 */
#[AsCommand(
    name: 'essayist:review-zap-claims',
    description: 'Review and manage pending Essayist zap claims'
)]
class EssayistReviewZapClaimsCommand extends Command
{
    public function __construct(
        private readonly EssayistZapClaimRepository $claimRepository,
        private readonly EssayistZapClaimService $claimService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('claim-id', InputArgument::OPTIONAL, 'Claim ID to process')
            ->addOption('reject', null, InputOption::VALUE_OPTIONAL, 'Reject the claim with a reason', false)
            ->addOption('amount', null, InputOption::VALUE_OPTIONAL, 'Amount in sats to approve with');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $claimIdArg = $input->getArgument('claim-id');

        // If a claim ID was provided, process that specific claim
        if ($claimIdArg) {
            $claimId = (int) $claimIdArg;
            $claim = $this->claimRepository->find($claimId);

            if ($claim === null) {
                $io->error(sprintf('Claim #%d not found.', $claimId));
                return Command::FAILURE;
            }

            return $this->processClaim($claim, $input, $io);
        }

        // Otherwise, list all pending claims
        return $this->listPendingClaims($io);
    }

    private function listPendingClaims(SymfonyStyle $io): int
    {
        $pending = $this->claimRepository->findAllPending();

        if (empty($pending)) {
            $io->success('No pending zap claims.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Pending Essayist Zap Claims (%d)', count($pending)));

        $rows = [];
        foreach ($pending as $claim) {
            $proof = '';
            if ($claim->getZapReceiptEventId()) {
                $receipt = $claim->getZapReceiptEventId();
                $proof = 'Receipt: ' . substr($receipt, 0, 16) . '…';
            } elseif ($claim->getBolt11Invoice()) {
                $invoice = $claim->getBolt11Invoice();
                $proof = 'Invoice: ' . substr($invoice, 0, 16) . '…';
            }

            $rows[] = [
                $claim->getId(),
                $claim->getUser()->getNpub() ? substr($claim->getUser()->getNpub(), 0, 16) . '…' : 'N/A',
                substr($claim->getSponsorPubkey(), 0, 16) . '…',
                $proof,
                $claim->getClaimedAmountSats() ?? '?',
                $claim->getCreatedAt()->format('Y-m-d H:i'),
            ];
        }

        $io->table(
            ['ID', 'Payer', 'Sponsor', 'Proof', 'Sats', 'Created'],
            $rows
        );

        $io->info('Run: bin/console essayist:review-zap-claims <claim-id> to process a claim');
        $io->info('Approve: bin/console essayist:review-zap-claims <id> --amount=<sats>');
        $io->info('Reject:  bin/console essayist:review-zap-claims <id> --reject="reason"');

        return Command::SUCCESS;
    }

    private function processClaim(EssayistZapClaim $claim, InputInterface $input, SymfonyStyle $io): int
    {
        $io->section(sprintf('Processing Claim #%d', $claim->getId()));

        $io->writeln(sprintf('Payer:       %s', $claim->getPayerPubkey()));
        $io->writeln(sprintf('Sponsor:     %s', $claim->getSponsorPubkey()));
        $io->writeln(sprintf('Status:      %s', $claim->getStatus()));
        $io->writeln(sprintf('Created:     %s', $claim->getCreatedAt()->format('Y-m-d H:i:s')));

        if ($claim->getZapReceiptEventId()) {
            $io->writeln(sprintf('Receipt ID:  %s', $claim->getZapReceiptEventId()));
        }

        if ($claim->getBolt11Invoice()) {
            $io->writeln(sprintf('Invoice:     %s', substr($claim->getBolt11Invoice(), 0, 50) . '…'));
        }

        if ($claim->getClaimedAmountSats()) {
            $io->writeln(sprintf('Claimed:     %d sats', $claim->getClaimedAmountSats()));
        }

        // Check for rejection
        $rejectReason = $input->getOption('reject');
        if ($rejectReason !== false) {
            if ($rejectReason === null) {
                $rejectReason = $io->askQuestion(
                    new \Symfony\Component\Console\Question\Question('Rejection reason: ')
                );
            }

            if (empty($rejectReason)) {
                $io->error('Rejection reason cannot be empty.');
                return Command::FAILURE;
            }

            $this->claimService->rejectClaim($claim, $rejectReason);
            $io->success(sprintf('Claim #%d rejected: %s', $claim->getId(), $rejectReason));

            return Command::SUCCESS;
        }

        // Check for approval
        $amountOption = $input->getOption('amount');
        if ($amountOption !== null) {
            $amount = (int) $amountOption;
            if ($amount < 1) {
                $io->error('Amount must be a positive integer.');
                return Command::FAILURE;
            }

            if ($this->claimService->approveClaim($claim, $amount)) {
                $io->success(sprintf('Claim #%d approved for %d sats', $claim->getId(), $amount));
                return Command::SUCCESS;
            } else {
                $io->error(sprintf('Failed to approve claim #%d', $claim->getId()));
                return Command::FAILURE;
            }
        }

        // Interactive mode
        $choice = $io->choice(
            'What action would you like to take?',
            ['Approve', 'Reject', 'Skip'],
            'Skip'
        );

        if ($choice === 'Approve') {
            $defaultAmount = $claim->getClaimedAmountSats() ?? 1000;
            $amount = (int) $io->ask('Approve for how many sats?', (string) $defaultAmount);
            if ($amount < 1) {
                $io->error('Invalid amount.');
                return Command::FAILURE;
            }

            if ($this->claimService->approveClaim($claim, $amount)) {
                $io->success(sprintf('Claim #%d approved for %d sats', $claim->getId(), $amount));
                return Command::SUCCESS;
            }
        } elseif ($choice === 'Reject') {
            $reason = $io->ask('Rejection reason: ');
            if (empty($reason)) {
                $io->error('Reason required.');
                return Command::FAILURE;
            }

            $this->claimService->rejectClaim($claim, $reason);
            $io->success(sprintf('Claim #%d rejected', $claim->getId()));
            return Command::SUCCESS;
        }

        $io->writeln('Skipped.');
        return Command::SUCCESS;
    }
}

