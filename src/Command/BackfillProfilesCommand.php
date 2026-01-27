<?php

namespace App\Command;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Nostr\NostrRelayPool;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use swentel\nostr\Filter\Filter;
use swentel\nostr\Message\RequestMessage;
use swentel\nostr\Subscription\Subscription;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'profiles:backfill',
    description: 'Backfill profile metadata (kind 0) from local relay to database'
)]
class BackfillProfilesCommand extends Command
{
    public function __construct(
        private readonly NostrRelayPool $relayPool,
        private readonly EventRepository $eventRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly RedisCacheService $redisCacheService,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Maximum number of profiles to fetch (for testing)',
                10000
            )
            ->addOption(
                'cache',
                'c',
                InputOption::VALUE_NONE,
                'Also cache profiles in Redis after saving to database'
            )
            ->setHelp(
                'This command fetches profile metadata (kind 0) events from the local Nostr relay ' .
                'and saves them to the database. Optionally caches them in Redis.' . "\n\n" .
                'Examples:' . "\n" .
                '  # Backfill all profiles from local relay:' . "\n" .
                '  php bin/console profiles:backfill' . "\n\n" .
                '  # Backfill and cache in Redis:' . "\n" .
                '  php bin/console profiles:backfill --cache' . "\n\n" .
                '  # Fetch only 100 profiles for testing:' . "\n" .
                '  php bin/console profiles:backfill --limit=100'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');
        $cacheInRedis = $input->getOption('cache');

        $io->title('Profile Metadata Backfill from Local Relay');

        // Check if local relay is configured
        $localRelay = $this->relayPool->getLocalRelay();
        if (!$localRelay) {
            $io->error('Local relay not configured. Set NOSTR_DEFAULT_RELAY environment variable.');
            return Command::FAILURE;
        }

        $io->info(sprintf('Fetching profile metadata from local relay: %s', $localRelay));
        $io->newLine();

        try {
            // Fetch metadata events (kind 0) from local relay
            $io->section('Fetching metadata events...');

            $subscription = new Subscription();
            $subscriptionId = $subscription->setId();

            $filter = new Filter();
            $filter->setKinds([KindsEnum::METADATA->value]); // kind 0
            $filter->setLimit($limit);

            $requestMessage = new RequestMessage($subscriptionId, [$filter]);

            // Send request to local relay
            $responses = $this->relayPool->sendToRelays(
                [$localRelay],
                fn() => $requestMessage,
                30,
                $subscriptionId
            );

            // Process responses
            $metadataEvents = [];
            foreach ($responses as $relayUrl => $relayResponses) {
                if (is_array($relayResponses)) {
                    foreach ($relayResponses as $response) {
                        if ($response->type === 'EVENT' && isset($response->event)) {
                            $metadataEvents[] = $response->event;
                        }
                    }
                }
            }

            if (empty($metadataEvents)) {
                $io->warning('No metadata events found in local relay.');
                return Command::SUCCESS;
            }

            $io->writeln(sprintf('Found %d metadata events', count($metadataEvents)));
            $io->newLine();

            // Save to database
            $io->section('Saving to database...');
            $progressBar = $io->createProgressBar(count($metadataEvents));
            $progressBar->setFormat('very_verbose');

            $savedCount = 0;
            $skippedCount = 0;
            $cachedCount = 0;
            $errors = 0;

            foreach ($metadataEvents as $event) {
                try {
                    // Check if event already exists
                    $existingEvent = $this->eventRepository->findById($event->id);

                    if ($existingEvent) {
                        $skippedCount++;
                        $progressBar->advance();
                        continue;
                    }

                    // Create new Event entity
                    $metadataEvent = new Event();
                    $metadataEvent->setId($event->id);
                    $metadataEvent->setPubkey($event->pubkey);
                    $metadataEvent->setCreatedAt($event->created_at ?? time());
                    $metadataEvent->setKind($event->kind);
                    $metadataEvent->setTags($event->tags ?? []);
                    $metadataEvent->setContent($event->content ?? '');
                    $metadataEvent->setSig($event->sig ?? '');

                    $this->entityManager->persist($metadataEvent);
                    $savedCount++;

                    // Optionally cache in Redis
                    if ($cacheInRedis) {
                        try {
                            $metadata = json_decode($event->content ?? '{}', true);
                            if (is_array($metadata)) {
                                $this->redisCacheService->setMetadata($event->pubkey, $metadata);
                                $cachedCount++;
                            }
                        } catch (\Throwable $e) {
                            $this->logger->warning('Failed to cache metadata in Redis', [
                                'pubkey' => $event->pubkey,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }

                    // Flush in batches of 50
                    if ($savedCount % 50 === 0) {
                        $this->entityManager->flush();
                        $this->logger->info('Batch persisted metadata events', ['count' => 50]);
                    }

                    $progressBar->advance();

                } catch (\Throwable $e) {
                    $this->logger->error('Failed to save metadata event', [
                        'event_id' => $event->id ?? 'unknown',
                        'pubkey' => $event->pubkey ?? 'unknown',
                        'error' => $e->getMessage()
                    ]);
                    $errors++;
                    $progressBar->advance();
                }
            }

            // Final flush
            if ($savedCount > 0) {
                $this->entityManager->flush();
            }

            $progressBar->finish();
            $io->newLine(2);

            // Summary
            $io->success('Profile metadata backfill complete!');
            $io->table(
                ['Metric', 'Count'],
                [
                    ['Total metadata events found', count($metadataEvents)],
                    ['Saved to database', $savedCount],
                    ['Already existed (skipped)', $skippedCount],
                    ['Cached in Redis', $cachedCount],
                    ['Errors', $errors],
                ]
            );

            if ($cacheInRedis) {
                $io->note('Metadata has been cached in Redis and saved to database.');
            } else {
                $io->note([
                    'Metadata has been saved to database.',
                    'To also cache in Redis, run with: --cache option'
                ]);
            }

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $io->error('Failed to backfill profiles: ' . $e->getMessage());
            $this->logger->error('Profile backfill failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }
}
