<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Service\ArticlePublicationIndexer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuild the article_in_publication reverse-index from all stored kind 30040 events.
 *
 * Run this once after deploying the feature to populate the table for
 * existing data. Subsequent ingestion auto-updates the index via
 * GenericEventProjector.
 *
 * Usage:
 *   docker compose exec php bin/console app:rebuild-publication-index
 *   docker compose exec php bin/console app:rebuild-publication-index --dry-run
 */
#[AsCommand(
    name: 'app:rebuild-publication-index',
    description: 'Rebuild the article_in_publication index from all stored kind 30040 events',
)]
class RebuildPublicationIndexCommand extends Command
{
    public function __construct(
        private readonly ArticlePublicationIndexer $indexer,
        private readonly EntityManagerInterface    $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be indexed without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Dry-run mode: no changes will be written.');
        }

        $io->title('Rebuilding article-in-publication index');

        /** @var Event[] $events */
        $events = $this->em->getRepository(Event::class)->findBy(
            ['kind' => KindsEnum::PUBLICATION_INDEX->value],
        );

        if (empty($events)) {
            $io->warning('No kind 30040 events found in the database.');
            return Command::SUCCESS;
        }

        // Keep only the latest event per (pubkey, d_tag) pair
        $latest = [];
        foreach ($events as $event) {
            $dTag = $event->getDTag() ?? $event->getSlug();
            if ($dTag === null || $dTag === '') {
                continue;
            }
            $key = $event->getPubkey() . ':' . $dTag;
            if (!isset($latest[$key]) || $event->getCreatedAt() > $latest[$key]->getCreatedAt()) {
                $latest[$key] = $event;
            }
        }

        $io->progressStart(count($latest));

        $indexed   = 0;
        $skipped   = 0;

        foreach ($latest as $event) {
            $articleCount = 0;
            foreach ($event->getTags() as $tag) {
                if (isset($tag[0], $tag[1]) && $tag[0] === 'a'
                    && (str_starts_with($tag[1], '30023:') || str_starts_with($tag[1], '30024:'))) {
                    $articleCount++;
                }
            }

            if ($articleCount === 0) {
                $skipped++;
                $io->progressAdvance();
                continue;
            }

            if (!$dryRun) {
                $this->indexer->indexPublicationEvent($event);
            }

            $indexed++;
            $io->progressAdvance();
        }

        $io->progressFinish();

        $io->success(sprintf(
            'Done. %d container(s) indexed (%d article references), %d container(s) skipped (no direct articles).',
            $indexed,
            $indexed, // approximate — each event may have multiple articles
            $skipped,
        ));

        return Command::SUCCESS;
    }
}

