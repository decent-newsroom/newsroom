<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\EventDeletionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-shot backfill: replay every kind:5 event already stored in the database
 * through {@see EventDeletionService::processDeletionRequest}, so any targets
 * that were ingested before NIP-09 handling existed are now removed and
 * tombstoned.
 *
 * Safe to re-run: tombstones are idempotent (unique on target_ref) and the
 * cascade uses targeted DELETE queries with pubkey/kind scoping.
 *
 * Usage:
 *   docker compose exec php bin/console events:replay-deletions
 *   docker compose exec php bin/console events:replay-deletions --dry-run
 *   docker compose exec php bin/console events:replay-deletions --batch-size=25
 *   docker compose exec php bin/console events:replay-deletions --pubkey=<hex>
 *   docker compose exec php bin/console events:replay-deletions --since=1735689600
 */
#[AsCommand(
    name: 'events:replay-deletions',
    description: 'Re-apply stored NIP-09 (kind:5) deletion requests against the local database',
)]
class ReplayDeletionRequestsCommand extends Command
{
    private const DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private readonly EventDeletionService $eventDeletionService,
        private readonly ManagerRegistry $managerRegistry,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List deletion requests that would be processed without touching the DB')
            ->addOption('pubkey', null, InputOption::VALUE_OPTIONAL, 'Only process kind:5 events from this hex pubkey')
            ->addOption('since', null, InputOption::VALUE_OPTIONAL, 'Only process kind:5 events with created_at >= this unix timestamp')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Cap the number of deletion requests to process')
            ->addOption('batch-size', null, InputOption::VALUE_OPTIONAL, 'How many deletion requests to load per Doctrine batch', (string) self::DEFAULT_BATCH_SIZE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $pubkey = $input->getOption('pubkey');
        $since = $input->getOption('since') !== null ? (int) $input->getOption('since') : null;
        $limit = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;
        $batchSize = max(1, (int) $input->getOption('batch-size'));

        $total = (int) $this->createDeletionRequestQueryBuilder($pubkey, $since)
            ->resetDQLPart('orderBy')
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();

        if ($total === 0) {
            $io->success('No kind:5 deletion requests found in the database.');
            return Command::SUCCESS;
        }

        $io->title(sprintf(
            'Replaying %d kind:5 deletion request(s)%s',
            $limit !== null ? min($limit, $total) : $total,
            $dryRun ? ' [DRY RUN]' : ''
        ));

        $processed = 0;
        $totalTargets = ['processed' => 0, 'suppressed' => 0, 'skipped' => 0];
        $offset = 0;
        $toProcess = $limit !== null ? min($limit, $total) : $total;

        $progress = $io->createProgressBar($toProcess);
        $progress->start();

        while ($processed < $toProcess) {
            $batchRows = $this->createDeletionRequestQueryBuilder($pubkey, $since)
                ->select('e.id')
                ->setFirstResult($offset)
                ->setMaxResults(min($batchSize, $toProcess - $processed))
                ->getQuery()
                ->getArrayResult();

            $batchIds = array_map(
                static fn (array $row): string => (string) $row['id'],
                $batchRows,
            );

            if ($batchIds === []) {
                break;
            }

            foreach ($batchIds as $deletionRequestId) {
                $deletionRequest = $this->loadDeletionRequest($deletionRequestId);

                if (!$deletionRequest instanceof Event) {
                    $this->logger->warning('events:replay-deletions skipped missing request from current batch', [
                        'deletion_event' => $deletionRequestId,
                    ]);

                    $processed++;
                    $progress->advance();
                    $this->releasePersistenceState();

                    continue;
                }

                if ($dryRun) {
                    $this->describeDryRun($io, $deletionRequest);
                } else {
                    try {
                        $result = $this->eventDeletionService->processDeletionRequest($deletionRequest);
                        $totalTargets['processed']  += $result['processed'];
                        $totalTargets['suppressed'] += $result['suppressed'];
                        $totalTargets['skipped']    += $result['skipped'];
                    } catch (\Throwable $e) {
                        $this->logger->error('events:replay-deletions failed on request', [
                            'deletion_event' => $deletionRequest->getId(),
                            'error' => $e->getMessage(),
                        ]);
                        $io->warning(sprintf('Failed on %s: %s', $deletionRequest->getId(), $e->getMessage()));
                    }
                }

                $processed++;
                $progress->advance();
                $this->releasePersistenceState();

                if ($processed >= $toProcess) {
                    break;
                }
            }

            $offset += count($batchIds);
            $this->releasePersistenceState();
        }

        $progress->finish();
        $io->newLine(2);

        if ($dryRun) {
            $io->success(sprintf('[DRY RUN] %d kind:5 request(s) would be replayed.', $processed));
            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Replayed %d kind:5 request(s). Targets: processed=%d, suppressed-only=%d, skipped=%d.',
            $processed,
            $totalTargets['processed'],
            $totalTargets['suppressed'],
            $totalTargets['skipped'],
        ));

        return Command::SUCCESS;
    }

    private function createDeletionRequestQueryBuilder(?string $pubkey, ?int $since): QueryBuilder
    {
        $qb = $this->eventRepository()->createQueryBuilder('e')
            ->where('e.kind = :kind')
            ->setParameter('kind', KindsEnum::DELETION_REQUEST->value)
            ->orderBy('e.created_at', 'ASC');

        if ($pubkey !== null) {
            $qb->andWhere('e.pubkey = :pubkey')->setParameter('pubkey', $pubkey);
        }

        if ($since !== null) {
            $qb->andWhere('e.created_at >= :since')->setParameter('since', $since);
        }

        return $qb;
    }

    private function eventRepository(): EventRepository
    {
        $repository = $this->loadEntityManager()->getRepository(Event::class);
        \assert($repository instanceof EventRepository);

        return $repository;
    }

    private function loadEntityManager(): EntityManagerInterface
    {
        $em = $this->managerRegistry->getManagerForClass(Event::class);

        if (!$em instanceof EntityManagerInterface || !$em->isOpen()) {
            $em = $this->managerRegistry->resetManager();
        }

        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function loadDeletionRequest(string $eventId): ?Event
    {
        $event = $this->loadEntityManager()->find(Event::class, $eventId);

        return $event instanceof Event ? $event : null;
    }

    private function releasePersistenceState(): void
    {
        $em = $this->managerRegistry->getManagerForClass(Event::class);

        if ($em instanceof EntityManagerInterface && $em->isOpen()) {
            $em->clear();

            return;
        }

        $this->managerRegistry->resetManager();
    }

    private function describeDryRun(SymfonyStyle $io, Event $deletionRequest): void
    {
        $eTags = 0;
        $aTags = 0;
        foreach ($deletionRequest->getTags() as $tag) {
            if (!is_array($tag) || !isset($tag[0])) { continue; }
            if ($tag[0] === 'e') { $eTags++; }
            elseif ($tag[0] === 'a') { $aTags++; }
        }
        $io->writeln(sprintf(
            '  • %s by %s… (created_at=%d) — %d e-ref(s), %d a-ref(s)',
            substr($deletionRequest->getId(), 0, 12),
            substr($deletionRequest->getPubkey(), 0, 12),
            $deletionRequest->getCreatedAt(),
            $eTags,
            $aTags,
        ));
    }
}

