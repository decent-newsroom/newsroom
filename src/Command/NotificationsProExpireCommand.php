<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\NotificationProService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Moves Notifications Pro subscriptions through the expiry state machine:
 *  ACTIVE (expiresAt passed) → GRACE
 *  GRACE  (graceEndsAt passed) → EXPIRED  + revoke ROLE_NOTIFICATIONS_PRO
 *
 * Run via cron once per hour (or each time active-indexing:check-receipts runs).
 */
#[AsCommand(
    name: 'notifications-pro:expire-subscriptions',
    description: 'Expire Notifications Pro subscriptions that have lapsed their grace period',
)]
class NotificationsProExpireCommand extends Command
{
    public function __construct(
        private readonly NotificationProService $proService,
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

