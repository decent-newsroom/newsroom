<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\UpdateProService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Moves Updates Pro subscriptions through the expiry state machine:
 *  ACTIVE (expiresAt passed) → GRACE
 *  GRACE  (graceEndsAt passed) → EXPIRED  + revoke ROLE_UPDATES_PRO
 *
 * Run via cron once per hour (or each time active-indexing:check-receipts runs).
 */
#[AsCommand(
    name: 'updates-pro:expire-subscriptions',
    description: 'Expire Updates Pro subscriptions that have lapsed their grace period',
)]
class UpdatesProExpireCommand extends Command
{
    public function __construct(
        private readonly UpdateProService $proService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $toGrace = $this->proService->processExpiredToGrace();
        if ($toGrace) {
            $io->info(sprintf('Moved %d subscription(s) to grace period.', $toGrace));
        }

        $expired = $this->proService->processGraceEnded();
        if ($expired) {
            $io->warning(sprintf('Permanently expired %d subscription(s) and revoked Pro role.', $expired));
        }

        if (!$toGrace && !$expired) {
            $io->info('Nothing to expire.');
        }

        return Command::SUCCESS;
    }
}

