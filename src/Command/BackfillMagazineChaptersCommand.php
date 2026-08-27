<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Message\FetchEventFromRelaysMessage;
use App\Repository\EventRepository;
use App\Service\Magazine\MagazineStructureService;
use App\Service\Nostr\EventLookupKey;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'magazines:backfill-chapters',
    description: 'Queue async fetches for missing kind 30041 chapters referenced by existing publication indexes',
)]
final class BackfillMagazineChaptersCommand extends Command
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly MagazineStructureService $magazineStructure,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List missing chapter fetches without dispatching messages')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Maximum publication index rows to scan')
            ->addOption('batch-size', null, InputOption::VALUE_OPTIONAL, 'Publication index rows to scan per database batch', 100);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $batchSize = max(1, (int) $input->getOption('batch-size'));
        $limitOption = $input->getOption('limit');
        $remaining = is_numeric($limitOption) ? max(0, (int) $limitOption) : null;

        if ($remaining === 0) {
            $io->warning('Limit is 0; no publication indexes scanned.');
            return Command::SUCCESS;
        }

        $io->title('Backfilling publication chapters');
        if ($dryRun) {
            $io->note('Dry-run mode — messages will not be dispatched.');
        }

        $offset = 0;
        $scanned = 0;
        $queued = 0;
        $seenCoordinates = [];

        try {
            $conn = $this->eventRepository->getEntityManager()->getConnection();

            do {
                $currentBatchSize = $remaining === null ? $batchSize : min($batchSize, $remaining);
                if ($currentBatchSize <= 0) {
                    break;
                }

                $rows = $conn->executeQuery(
                    'SELECT * FROM event WHERE kind = :kind ORDER BY created_at DESC, id ASC LIMIT :limit OFFSET :offset',
                    [
                        'kind' => KindsEnum::PUBLICATION_INDEX->value,
                        'limit' => $currentBatchSize,
                        'offset' => $offset,
                    ],
                    [
                        'kind' => ParameterType::INTEGER,
                        'limit' => ParameterType::INTEGER,
                        'offset' => ParameterType::INTEGER,
                    ],
                )->fetchAllAssociative();

                if ($rows === []) {
                    break;
                }

                $scanned += count($rows);
                $offset += count($rows);
                if ($remaining !== null) {
                    $remaining -= count($rows);
                }

                foreach ($rows as $row) {
                    $magazine = $this->magazineStructure->hydrateEventFromRow($row);
                    foreach ($this->magazineStructure->missingChapterFetchRequests($magazine) as $request) {
                        if (isset($seenCoordinates[$request['coordinate']])) {
                            continue;
                        }
                        $seenCoordinates[$request['coordinate']] = true;

                        $mag = $magazine->getDTag() ?: $magazine->getSlug();
                        $lookupKey = EventLookupKey::forNaddr($request['kind'], $request['pubkey'], $request['identifier']);

                        if ($dryRun) {
                            $io->writeln(sprintf(
                                'Would queue %s%s',
                                $request['coordinate'],
                                $mag ? sprintf(' for magazine "%s"', $mag) : '',
                            ));
                        } else {
                            $this->messageBus->dispatch(new FetchEventFromRelaysMessage(
                                lookupKey: $lookupKey,
                                type: 'naddr',
                                kind: $request['kind'],
                                pubkey: $request['pubkey'],
                                identifier: $request['identifier'],
                                relays: $request['relayHints'],
                                mag: $mag,
                            ));
                        }

                        $queued++;
                    }
                }
            } while ($remaining === null || $remaining > 0);
        } catch (\Throwable $e) {
            $this->logger->error('Publication chapter backfill failed', [
                'error' => $e->getMessage(),
            ]);
            $io->error('Backfill failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $this->logger->info('Publication chapter backfill scan completed', [
            'scanned' => $scanned,
            'queued' => $queued,
            'dry_run' => $dryRun,
        ]);

        $io->success(sprintf(
            'Scanned %d publication index row(s); %s %d missing chapter fetch(es).',
            $scanned,
            $dryRun ? 'found' : 'queued',
            $queued,
        ));

        return Command::SUCCESS;
    }
}
