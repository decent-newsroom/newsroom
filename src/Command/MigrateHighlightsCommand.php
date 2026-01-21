<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Event;
use App\Entity\Highlight;
use App\Enum\KindsEnum;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:migrate-highlights',
    description: 'Migrate highlight Event entities (kind 9802) to Highlight entities'
)]
class MigrateHighlightsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Migrating Highlight Events to Highlight Entities');

        try {
            // Find all Event entities with kind 9802 (HIGHLIGHTS)
            $eventRepository = $this->entityManager->getRepository(Event::class);
            $highlightEvents = $eventRepository->findBy(['kind' => KindsEnum::HIGHLIGHTS->value]);

            $io->text(sprintf('Found %d Event entities with kind 9802 (highlights)', count($highlightEvents)));

            if (empty($highlightEvents)) {
                $io->success('No highlight events to migrate');
                return Command::SUCCESS;
            }

            $migrated = 0;
            $skipped = 0;
            $errors = 0;

            $progressBar = $io->createProgressBar(count($highlightEvents));
            $progressBar->start();

            foreach ($highlightEvents as $event) {
                try {
                    $eventId = $event->getEventId();

                    // Check if Highlight already exists
                    $existingHighlight = $this->entityManager->getRepository(Highlight::class)
                        ->findOneBy(['eventId' => $eventId]);

                    if ($existingHighlight) {
                        $this->logger->debug('Highlight already exists, skipping Event', ['event_id' => $eventId]);
                        $skipped++;
                        $progressBar->advance();
                        continue;
                    }

                    // Extract article coordinate from tags
                    $articleCoordinate = null;
                    $context = null;
                    $tags = $event->getTags() ?? [];

                    foreach ($tags as $tag) {
                        if (is_array($tag) && count($tag) >= 2) {
                            if (in_array($tag[0], ['a', 'A'])) {
                                // Check for article reference (kind 30023)
                                if (str_starts_with($tag[1] ?? '', '30023:')) {
                                    $articleCoordinate = $tag[1];
                                }
                            }
                            // Extract context if available
                            if ($tag[0] === 'context' && isset($tag[1])) {
                                $context = $tag[1];
                            }
                        }
                    }

                    // Create new Highlight entity (articleCoordinate is optional)
                    $highlight = new Highlight();
                    $highlight->setEventId($eventId);
                    $highlight->setArticleCoordinate($articleCoordinate);
                    $highlight->setContent($event->getContent() ?? '');
                    $highlight->setPubkey($event->getPubkey() ?? '');
                    $highlight->setCreatedAt($event->getCreatedAt() ?? time());
                    $highlight->setContext($context);
                    $highlight->setRawEvent([
                        'id' => $event->getEventId(),
                        'kind' => $event->getKind(),
                        'pubkey' => $event->getPubkey(),
                        'content' => $event->getContent(),
                        'created_at' => $event->getCreatedAt(),
                        'tags' => $event->getTags(),
                        'sig' => $event->getSig(),
                    ]);

                    $this->entityManager->persist($highlight);

                    // Remove the old Event entity
                    $this->entityManager->remove($event);

                    $migrated++;

                    // Flush in batches of 20
                    if ($migrated % 20 === 0) {
                        $this->entityManager->flush();
                        $this->entityManager->clear();
                    }

                    $this->logger->debug('Migrated Event to Highlight', [
                        'event_id' => $eventId,
                        'article_coordinate' => $articleCoordinate,
                    ]);
                } catch (\Exception $e) {
                    $this->logger->error('Failed to migrate Event to Highlight', [
                        'event_id' => $event->getEventId() ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                    $errors++;
                }

                $progressBar->advance();
            }

            // Final flush
            $this->entityManager->flush();
            $progressBar->finish();
            $io->newLine(2);

            // Summary
            $io->success('Migration completed!');
            $io->table(
                ['Status', 'Count'],
                [
                    ['Total found', count($highlightEvents)],
                    ['Migrated', $migrated],
                    ['Skipped (already exists or no coordinate)', $skipped],
                    ['Errors', $errors],
                ]
            );

            $this->logger->info('Migrated highlight Events to Highlight entities', [
                'migrated' => $migrated,
                'skipped' => $skipped,
                'errors' => $errors,
                'total' => count($highlightEvents)
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Failed to migrate highlights: ' . $e->getMessage());
            $this->logger->error('Failed to migrate highlights', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }
}
