<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Graph\CurrentVersionResolver;
use App\Service\Graph\GraphLookupService;
use App\Service\Graph\RecordIdentityService;
use App\Service\Graph\ReferenceParserService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuild the graph layer (current_record + parsed_reference) for a single coordinate.
 *
 * Re-evaluates all events matching the coordinate, re-runs newest-wins resolution,
 * and re-parses references for the winning event.
 *
 * Usage:
 *   dn:graph:rebuild-record "30040:<pubkey>:<slug>"
 *   dn:graph:rebuild-record "30040:<pubkey>:<slug>" --cascade
 */
#[AsCommand(
    name: 'dn:graph:rebuild-record',
    description: 'Rebuild graph data (current_record + parsed_reference) for a single coordinate',
)]
class GraphRebuildRecordCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CurrentVersionResolver $currentVersionResolver,
        private readonly ReferenceParserService $referenceParser,
        private readonly RecordIdentityService $identityService,
        private readonly GraphLookupService $graphLookup,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('coordinate', InputArgument::REQUIRED, 'Coordinate to rebuild (e.g. "30040:<pubkey>:<slug>")')
            ->addOption('cascade', 'c', InputOption::VALUE_NONE, 'Also rebuild structural children recursively')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be done without changing data')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $coord = $input->getArgument('coordinate');
        $cascade = $input->getOption('cascade');
        $dryRun = $input->getOption('dry-run');

        $io->title('Rebuild Graph Record');
        $io->info('Coordinate: ' . $coord);

        if ($dryRun) {
            $io->note('Dry-run mode — no data will be modified.');
        }

        // Decompose the coordinate
        $decomposed = $this->identityService->decomposeATag($coord);
        if ($decomposed === null) {
            $io->error('Invalid coordinate format. Expected "<kind>:<pubkey>:<d_tag>".');
            return Command::FAILURE;
        }

        $rebuilt = $this->rebuildCoordinate($io, $coord, $decomposed, $dryRun);

        if ($cascade) {
            $io->section('Cascading to children');
            $this->cascadeRebuild($io, $coord, $dryRun);
        }

        $io->success(sprintf(
            'Rebuild complete for %s%s.',
            $coord,
            $cascade ? ' (with cascade)' : '',
        ));

        $this->logger->info('Graph rebuild complete', [
            'coord' => $coord,
            'cascade' => $cascade,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Rebuild current_record and parsed_reference for a single coordinate.
     */
    private function rebuildCoordinate(SymfonyStyle $io, string $coord, array $decomposed, bool $dryRun): bool
    {
        $kind = $decomposed['kind'];
        $pubkey = strtolower($decomposed['pubkey']);
        $dTag = $decomposed['d_tag'];

        // Find all events matching this coordinate in the event table
        $events = $this->connection->fetchAllAssociative(
            'SELECT id, kind, pubkey, created_at, d_tag, tags FROM event WHERE kind = :kind AND LOWER(pubkey) = :pubkey AND d_tag = :d_tag ORDER BY created_at ASC',
            ['kind' => $kind, 'pubkey' => $pubkey, 'd_tag' => $dTag],
        );

        // Also check the article table
        $articles = $this->connection->fetchAllAssociative(
            "SELECT event_id, kind, pubkey, slug, EXTRACT(EPOCH FROM created_at)::bigint AS created_at_ts FROM article WHERE kind = :kind AND LOWER(pubkey) = :pubkey AND slug = :slug AND event_id IS NOT NULL",
            ['kind' => $kind, 'pubkey' => $pubkey, 'slug' => $dTag],
        );

        $totalSources = count($events) + count($articles);
        $io->info(sprintf('Found %d event(s) + %d article(s) for coordinate.', count($events), count($articles)));

        if ($totalSources === 0) {
            $io->warning('No events found for this coordinate. Removing stale current_record if exists.');
            if (!$dryRun) {
                $this->connection->executeStatement(
                    'DELETE FROM current_record WHERE coord = ?',
                    [$coord],
                );
            }
            return false;
        }

        if ($dryRun) {
            $io->info('Would re-run newest-wins resolution and re-parse references.');
            return true;
        }

        // Clear existing current_record for this coordinate to rebuild from scratch
        $this->connection->executeStatement(
            'DELETE FROM current_record WHERE coord = ?',
            [$coord],
        );

        // Replay all events through CurrentVersionResolver in created_at ASC order
        foreach ($events as $event) {
            $this->currentVersionResolver->updateIfCurrent(
                eventId: $event['id'],
                kind: (int) $event['kind'],
                pubkey: $event['pubkey'],
                dTag: $event['d_tag'],
                createdAt: (int) $event['created_at'],
            );
        }

        // Also replay articles
        foreach ($articles as $article) {
            $this->currentVersionResolver->updateIfCurrent(
                eventId: $article['event_id'],
                kind: (int) $article['kind'],
                pubkey: $article['pubkey'],
                dTag: $article['slug'],
                createdAt: (int) $article['created_at_ts'],
            );
        }

        // Now re-parse references for the winning event
        $currentRecord = $this->currentVersionResolver->getCurrentRecord($coord);
        if ($currentRecord !== null) {
            $winnerEventId = $currentRecord['current_event_id'];
            $io->info(sprintf('Current winner: %s', substr($winnerEventId, 0, 16) . '…'));

            // Rebuild references
            $this->rebuildReferences($winnerEventId);
        }

        return true;
    }

    /**
     * Re-parse and store references for a specific event.
     */
    private function rebuildReferences(string $eventId): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, kind, tags FROM event WHERE id = ?',
            [$eventId],
        );

        if ($row === false) {
            // Try article table
            $artRow = $this->connection->fetchAssociative(
                'SELECT event_id, raw FROM article WHERE event_id = ? AND raw IS NOT NULL',
                [$eventId],
            );

            if ($artRow === false) {
                return;
            }

            $raw = is_string($artRow['raw']) ? json_decode($artRow['raw'], true) : $artRow['raw'];
            if (!is_array($raw)) {
                return;
            }

            $tags = $raw['tags'] ?? [];
            $kind = (int) ($raw['kind'] ?? 0);
        } else {
            $tags = is_string($row['tags']) ? json_decode($row['tags'], true) : $row['tags'];
            $kind = (int) $row['kind'];
        }

        if (!is_array($tags)) {
            return;
        }

        $refs = $this->referenceParser->parseFromTagsArray($eventId, $kind, $tags);

        // Delete existing references
        $this->connection->executeStatement(
            'DELETE FROM parsed_reference WHERE source_event_id = ?',
            [$eventId],
        );

        // Insert new
        foreach ($refs as $ref) {
            $this->connection->executeStatement(
                'INSERT INTO parsed_reference (source_event_id, tag_name, target_ref_type, target_kind, target_pubkey, target_d_tag, target_coord, relation, marker, position, is_structural, is_resolvable) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $ref->sourceEventId, $ref->tagName, $ref->targetRefType,
                    $ref->targetKind, $ref->targetPubkey, $ref->targetDTag,
                    $ref->targetCoord, $ref->relation, $ref->marker,
                    $ref->position, $ref->isStructural, $ref->isResolvable,
                ],
                [
                    ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                    ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING,
                    ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                    ParameterType::INTEGER, ParameterType::BOOLEAN, ParameterType::BOOLEAN,
                ],
            );
        }
    }

    /**
     * Cascade rebuild to structural children.
     */
    private function cascadeRebuild(SymfonyStyle $io, string $rootCoord, bool $dryRun): void
    {
        $descendants = $this->graphLookup->resolveDescendants($rootCoord, 5);

        if (empty($descendants)) {
            $io->info('No descendants found to cascade.');
            return;
        }

        $io->info(sprintf('Found %d descendant(s) to rebuild.', count($descendants)));

        foreach ($descendants as $desc) {
            $childCoord = $desc['coord'];
            $decomposed = $this->identityService->decomposeATag($childCoord);
            if ($decomposed === null) {
                continue;
            }

            $io->writeln(sprintf('  Rebuilding: %s (kind %d, depth %d)', $childCoord, $desc['kind'], $desc['depth']));

            if (!$dryRun) {
                $this->rebuildCoordinate($io, $childCoord, $decomposed, false);
            }
        }
    }
}


