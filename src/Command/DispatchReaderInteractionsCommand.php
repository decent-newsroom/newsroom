<?php

declare(strict_types=1);

namespace App\Command;

use App\Message\PublishReaderInteractionMessage;
use App\Unfold\InteractionOutboxStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Durable recovery dispatch for the reader-interaction outbox.
 *
 * Messenger queue middleware can silently swallow a dispatch failure (e.g. a
 * transient Redis blip right after the HTTP-path commit), and automatic
 * retries driven only by the in-flight message would be lost along with it.
 * This command is the durability backstop: it scans the outbox for rows that
 * are due (status=queued, next_attempt_at reached, not currently leased) and
 * re-dispatches them. Claiming uses a short, row-scoped lease
 * (`FOR UPDATE SKIP LOCKED` + `leased_until`), so concurrent runs of this
 * command (or an in-flight handler) never duplicate work and no database
 * lock is ever held across network I/O. A crashed worker's lease simply
 * expires and the row becomes claimable again on the next run.
 *
 * Intended to run once a minute via cron (see docker/cron/crontab).
 */
#[AsCommand(
    name: 'app:dispatch-reader-interactions',
    description: 'Recover due reader-interaction outbox rows and re-dispatch them for relay delivery',
)]
final class DispatchReaderInteractionsCommand extends Command
{
    public function __construct(
        private readonly InteractionOutboxStore $outbox,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Maximum number of due rows to claim and dispatch', '200')
            ->addOption('lease-seconds', null, InputOption::VALUE_OPTIONAL, 'Dispatch reservation duration applied to claimed rows', (string) InteractionOutboxStore::DEFAULT_DISPATCH_LEASE_SECONDS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $leaseSeconds = max(1, (int) $input->getOption('lease-seconds'));

        $eventIds = $this->outbox->claimDue($limit, $leaseSeconds);
        if ($eventIds === []) {
            $io->comment('No due reader-interaction outbox rows found.');

            return Command::SUCCESS;
        }

        $dispatched = 0;
        $failed = 0;

        foreach ($eventIds as $claim) {
            $eventId = $claim['event_id'];
            try {
                $this->messageBus->dispatch(new PublishReaderInteractionMessage($eventId, $claim['dispatch_lease']));
                $dispatched++;
            } catch (\Throwable $e) {
                // One failed dispatch must not block the rest of the batch;
                // the row's lease will simply expire and it will be reclaimed
                // on the next run.
                $failed++;
                $this->logger->error('Failed to re-dispatch reader interaction outbox row', [
                    'event_id' => $eventId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $io->writeln(sprintf('<info>Dispatched %d reader-interaction outbox row(s), %d failed to enqueue.</info>', $dispatched, $failed));

        return $failed > 0 && $dispatched === 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
