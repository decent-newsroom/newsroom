<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Article;
use App\Service\ArticleEventProjector;
use App\Service\Nostr\NostrRelayPool;
use App\Service\Nostr\RelayRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-shot backfill: re-ingest every longform/publication event currently stored
 * on the local strfry relay, skipping events already persisted in the database.
 *
 * Useful after a DB cleanup / projector bug that dropped articles while the
 * underlying Nostr events are still available on the local relay.
 */
#[AsCommand(
    name: 'articles:backfill-local',
    description: 'Backfill articles from the local Nostr relay into the database (re-projects any missing events)',
)]
class BackfillArticlesFromLocalRelayCommand extends Command
{
    /** @var array<int> */
    private const DEFAULT_KINDS = [30023];

    public function __construct(
        private readonly NostrRelayPool $relayPool,
        private readonly RelayRegistry $relayRegistry,
        private readonly ArticleEventProjector $projector,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('kinds', null, InputOption::VALUE_REQUIRED, 'Comma-separated event kinds to backfill', '30023')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only fetch events newer than this (unix ts or strtotime expression, e.g. "-90 days")')
            ->addOption('until', null, InputOption::VALUE_REQUIRED, 'Only fetch events older than this (unix ts or strtotime expression)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Optional per-filter limit sent to the relay')
            ->addOption('idle-timeout', null, InputOption::VALUE_REQUIRED, 'Seconds to wait with no relay traffic before giving up on EOSE', '30')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be ingested, do not persist')
            ->setHelp(<<<'HELP'
Re-ingests article events from the local Nostr relay (strfry) into the database.

The projector is idempotent — already-persisted events are skipped — so this is
safe to run repeatedly. Intended for repairing gaps caused by DB cleanup or past
projector bugs.

Examples:
  # Backfill all longform (30023) articles from the local relay
  bin/console articles:backfill-local

  # Backfill the last 90 days only
  bin/console articles:backfill-local --since="-90 days"

  # Backfill longform + publication indices/content
  bin/console articles:backfill-local --kinds=30023,30040,30041

  # Dry run — count missing articles without inserting
  bin/console articles:backfill-local --dry-run
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $localRelay = $this->relayRegistry->getLocalRelay();
        if (!$localRelay) {
            $io->error('Local relay is not configured (NOSTR_DEFAULT_RELAY).');
            return Command::FAILURE;
        }

        $kinds = $this->parseKinds((string) $input->getOption('kinds'));
        if ($kinds === []) {
            $io->error('No valid kinds supplied.');
            return Command::INVALID;
        }

        $since = $this->parseTimestamp($input->getOption('since'));
        $until = $this->parseTimestamp($input->getOption('until'));
        $limit = $input->getOption('limit') !== null ? (int) $input->getOption('limit') : null;
        $idleTimeout = max(5, (int) $input->getOption('idle-timeout'));
        $dryRun = (bool) $input->getOption('dry-run');

        if ($since === false || $until === false) {
            $io->error('Could not parse --since / --until value.');
            return Command::INVALID;
        }

        $io->title('Article Backfill from Local Relay');
        $io->definitionList(
            ['Relay' => $localRelay],
            ['Kinds' => implode(', ', $kinds)],
            ['Since' => $since !== null ? date('Y-m-d H:i:s', $since) . " ($since)" : '— beginning of time —'],
            ['Until' => $until !== null ? date('Y-m-d H:i:s', $until) . " ($until)" : '— now —'],
            ['Limit' => $limit !== null ? (string) $limit : 'none'],
            ['Mode'  => $dryRun ? 'DRY RUN (no writes)' : 'WRITE'],
        );

        $received = 0;
        $alreadyPresent = 0;
        $ingested = 0;
        $skipped = 0;
        $failed = 0;

        $start = microtime(true);

        try {
            $this->relayPool->fetchLocalUntilEose(
                kinds: $kinds,
                onEvent: function (object $event, string $relayUrl) use (
                    $io, $dryRun, &$received, &$alreadyPresent, &$ingested, &$skipped, &$failed
                ): void {
                    $received++;
                    $eventId = $event->id ?? null;
                    $kind = $event->kind ?? null;

                    if ($eventId === null) {
                        $skipped++;
                        return;
                    }

                    // Only kind 30023 is mapped to the Article entity; other kinds
                    // (e.g. 30040/30041) go through the projector which handles them.
                    $existing = null;
                    if ($kind === 30023) {
                        $existing = $this->em->getRepository(Article::class)
                            ->findOneBy(['eventId' => $eventId]);
                    }

                    if ($existing !== null) {
                        $alreadyPresent++;
                        if ($received % 100 === 0) {
                            $io->writeln(sprintf(
                                '  processed %d events (present: %d, ingested: %d, failed: %d)',
                                $received, $alreadyPresent, $ingested, $failed
                            ));
                        }
                        return;
                    }

                    if ($dryRun) {
                        $ingested++;
                        $io->writeln(sprintf(
                            '  <fg=yellow>would ingest</> %s (kind %s)',
                            substr($eventId, 0, 16) . '…',
                            (string) $kind,
                        ));
                        return;
                    }

                    try {
                        $this->projector->projectArticleFromEvent($event, $relayUrl);
                        $ingested++;
                        $io->writeln(sprintf(
                            '  <fg=green>✓</> ingested %s (kind %s)',
                            substr($eventId, 0, 16) . '…',
                            (string) $kind,
                        ));
                    } catch (\InvalidArgumentException $e) {
                        $skipped++;
                        $io->writeln(sprintf(
                            '  <fg=yellow>⚠</> skipped %s: %s',
                            substr($eventId, 0, 16) . '…',
                            $e->getMessage(),
                        ));
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->logger->error('Backfill: failed to project event', [
                            'event_id' => $eventId,
                            'kind' => $kind,
                            'error' => $e->getMessage(),
                        ]);
                        $io->writeln(sprintf(
                            '  <fg=red>✗</> failed %s: %s',
                            substr($eventId, 0, 16) . '…',
                            $e->getMessage(),
                        ));
                    }
                },
                since: $since,
                until: $until,
                limit: $limit,
                idleTimeoutSeconds: $idleTimeout,
            );
        } catch (\Throwable $e) {
            $io->error('Backfill failed: ' . $e->getMessage());
            $this->logger->error('Backfill failed', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            return Command::FAILURE;
        }

        $elapsed = microtime(true) - $start;

        $io->newLine();
        $io->success(sprintf('Backfill complete in %.1fs', $elapsed));
        $io->table(
            ['Events received', 'Already present', $dryRun ? 'Would ingest' : 'Ingested', 'Skipped', 'Failed'],
            [[$received, $alreadyPresent, $ingested, $skipped, $failed]],
        );

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return array<int>
     */
    private function parseKinds(string $raw): array
    {
        $kinds = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '' || !ctype_digit($part)) {
                continue;
            }
            $kinds[] = (int) $part;
        }
        return array_values(array_unique($kinds));
    }

    /**
     * @return int|null|false Returns null when no value given, false on parse error, int unix ts otherwise.
     */
    private function parseTimestamp(mixed $value): int|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        $ts = strtotime((string) $value);
        return $ts === false ? false : $ts;
    }
}

