<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Event;
use App\Service\NostrClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:fetch-highlights',
    description: 'Fetch highlights from Nostr relays and save them as Event entities'
)]
class FetchHighlightsCommand extends Command
{
    public function __construct(
        private readonly NostrClient $nostrClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            'l',
            InputOption::VALUE_OPTIONAL,
            'Maximum number of highlights to fetch',
            100
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');

        $io->title('Fetching Highlights from Nostr Relays');
        $io->text("Limit: {$limit} highlights");

        try {
            // Fetch highlights from Nostr
            $io->section('Fetching highlights from relays...');
            $events = $this->nostrClient->getArticleHighlights($limit);
            $io->success(sprintf('Fetched %d highlight events', count($events)));

            if (empty($events)) {
                $io->warning('No highlights found');
                return Command::SUCCESS;
            }

            // Save highlights as Event entities
            $io->section('Saving highlights to database...');
            $saved = 0;
            $skipped = 0;
            $errors = 0;

            $progressBar = $io->createProgressBar(count($events));
            $progressBar->start();

            foreach ($events as $nostrEvent) {
                try {
                    // Skip if event ID is missing
                    if (!isset($nostrEvent->id) || empty($nostrEvent->id)) {
                        $this->logger->warning('Skipping event without ID');
                        $skipped++;
                        $progressBar->advance();
                        continue;
                    }

                    // Check if event already exists
                    $existingEvent = $this->entityManager->getRepository(Event::class)->find($nostrEvent->id);
                    if ($existingEvent) {
                        $this->logger->debug('Event already exists, skipping', ['event_id' => $nostrEvent->id]);
                        $skipped++;
                        $progressBar->advance();
                        continue;
                    }

                    // Create new Event entity
                    $event = new Event();
                    $event->setId($nostrEvent->id);
                    $event->setEventId($nostrEvent->id);
                    $event->setKind($nostrEvent->kind ?? 9802);
                    $event->setPubkey($nostrEvent->pubkey ?? '');
                    $event->setContent($nostrEvent->content ?? '');
                    $event->setCreatedAt($nostrEvent->created_at ?? time());
                    $event->setTags($nostrEvent->tags ?? []);
                    $event->setSig($nostrEvent->sig ?? '');

                    $this->entityManager->persist($event);
                    $saved++;

                    // Flush in batches of 20 to improve performance
                    if ($saved % 20 === 0) {
                        $this->entityManager->flush();
                        $this->entityManager->clear();
                    }

                    $this->logger->debug('Saved highlight as Event entity', [
                        'event_id' => $nostrEvent->id,
                        'kind' => $nostrEvent->kind,
                        'pubkey' => substr($nostrEvent->pubkey ?? '', 0, 16),
                    ]);
                } catch (\Exception $e) {
                    $this->logger->error('Failed to save highlight as Event entity', [
                        'event_id' => $nostrEvent->id ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                    $errors++;
                }

                $progressBar->advance();
            }

            // Final flush for remaining events
            $this->entityManager->flush();
            $progressBar->finish();
            $io->newLine(2);

            // Summary
            $io->success('Highlights saved successfully!');
            $io->table(
                ['Status', 'Count'],
                [
                    ['Total fetched', count($events)],
                    ['Saved', $saved],
                    ['Skipped (already exists)', $skipped],
                    ['Errors', $errors],
                ]
            );

            $this->logger->info('Saved highlights as Event entities', [
                'saved' => $saved,
                'skipped' => $skipped,
                'errors' => $errors,
                'total' => count($events)
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error('Failed to fetch and save highlights: ' . $e->getMessage());
            $this->logger->error('Failed to fetch and save highlights', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }
}
