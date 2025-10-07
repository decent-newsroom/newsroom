<?php

namespace App\Command;

use App\Factory\ArticleFactory;
use App\Repository\ArticleRepository;
use App\Repository\NzineRepository;
use App\Service\EncryptionService;
use App\Service\NostrClient;
use App\Service\NzineCategoryIndexService;
use App\Service\RssFeedService;
use App\Service\RssToNostrConverter;
use App\Service\TagMatchingService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'nzine:rss:fetch',
    description: 'Fetch RSS feeds and publish as Nostr events for configured nzines',
)]
class RssFetchCommand extends Command
{
    private SymfonyStyle $io;

    public function __construct(
        private readonly NzineRepository $nzineRepository,
        private readonly ArticleRepository $articleRepository,
        private readonly RssFeedService $rssFeedService,
        private readonly TagMatchingService $tagMatchingService,
        private readonly RssToNostrConverter $rssToNostrConverter,
        private readonly ArticleFactory $articleFactory,
        private readonly NostrClient $nostrClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly EncryptionService $encryptionService,
        private readonly LoggerInterface $logger,
        private readonly NzineCategoryIndexService $categoryIndexService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('nzine-id', null, InputOption::VALUE_OPTIONAL, 'Process only this specific nzine ID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Test without actually publishing events')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Limit number of items to process per feed', 50);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        $nzineId = $input->getOption('nzine-id');
        $isDryRun = $input->getOption('dry-run');
        $limit = (int) $input->getOption('limit');

        $this->io->title('RSS Feed to Nostr Aggregator');

        if ($isDryRun) {
            $this->io->warning('Running in DRY-RUN mode - no events will be published');
        }

        // Get nzines to process
        $nzines = $nzineId
            ? [$this->nzineRepository->findRssNzineById((int) $nzineId)]
            : $this->nzineRepository->findActiveRssNzines();

        $nzines = array_filter($nzines); // Remove nulls

        if (empty($nzines)) {
            $this->io->warning('No RSS-enabled nzines found');
            return Command::SUCCESS;
        }

        $this->io->info(sprintf('Processing %d nzine(s)', count($nzines)));

        $totalStats = [
            'nzines_processed' => 0,
            'items_fetched' => 0,
            'items_matched' => 0,
            'items_skipped_duplicate' => 0,
            'items_skipped_unmatched' => 0,
            'events_created' => 0,
            'events_updated' => 0,
            'errors' => 0,
        ];

        foreach ($nzines as $nzine) {
            try {
                $stats = $this->processNzine($nzine, $isDryRun, $limit);

                // Aggregate stats
                foreach ($stats as $key => $value) {
                    $totalStats[$key] = ($totalStats[$key] ?? 0) + $value;
                }

                $totalStats['nzines_processed']++;
            } catch (\Exception $e) {
                $this->io->error(sprintf(
                    'Error processing nzine #%d: %s',
                    $nzine->getId(),
                    $e->getMessage()
                ));
                $this->logger->error('Nzine processing error', [
                    'nzine_id' => $nzine->getId(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                $totalStats['errors']++;
            }
        }

        // Display final statistics
        $this->io->success('RSS feed processing completed');
        $this->io->table(
            ['Metric', 'Count'],
            [
                ['Nzines processed', $totalStats['nzines_processed']],
                ['Items fetched', $totalStats['items_fetched']],
                ['Items matched', $totalStats['items_matched']],
                ['Events created', $totalStats['events_created']],
                ['Events updated', $totalStats['events_updated']],
                ['Duplicates skipped', $totalStats['items_skipped_duplicate']],
                ['Unmatched skipped', $totalStats['items_skipped_unmatched']],
                ['Errors', $totalStats['errors']],
            ]
        );

        return $totalStats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Process a single nzine's RSS feed
     */
    private function processNzine($nzine, bool $isDryRun, int $limit): array
    {
        $stats = [
            'items_fetched' => 0,
            'items_matched' => 0,
            'items_skipped_duplicate' => 0,
            'items_skipped_unmatched' => 0,
            'events_created' => 0,
            'events_updated' => 0,
        ];

        $this->io->section(sprintf('Processing Nzine #%d: %s', $nzine->getId(), $nzine->getSlug()));

        $feedUrl = $nzine->getFeedUrl();
        if (empty($feedUrl)) {
            $this->io->warning('No feed URL configured');
            return $stats;
        }

        // Fetch RSS feed
        try {
            $feedItems = $this->rssFeedService->fetchFeed($feedUrl);
            $stats['items_fetched'] = count($feedItems);

            $this->io->text(sprintf('Fetched %d items from feed', count($feedItems)));
        } catch (\Exception $e) {
            $this->io->error(sprintf('Failed to fetch feed: %s', $e->getMessage()));
            throw $e;
        }

        // Limit items if specified
        if ($limit > 0 && count($feedItems) > $limit) {
            $feedItems = array_slice($feedItems, 0, $limit);
            $this->io->text(sprintf('Limited to %d items', $limit));
        }

        // Get nzine categories
        $categories = $nzine->getMainCategories();
        if (empty($categories)) {
            $this->io->warning('No categories configured - skipping all items');
            $stats['items_skipped_unmatched'] = count($feedItems);
            return $stats;
        }

        // Ensure category index events exist in the database
        $categoryIndices = [];
        if (!$isDryRun) {
            $this->io->text('Ensuring category index events exist...');
            try {
                $categoryIndices = $this->categoryIndexService->ensureCategoryIndices($nzine);
                $this->io->text(sprintf('Category indices ready: %d', count($categoryIndices)));
            } catch (\Exception $e) {
                $this->io->warning(sprintf('Could not create category indices: %s', $e->getMessage()));
                $this->logger->warning('Category index creation failed', [
                    'nzine_id' => $nzine->getId(),
                    'error' => $e->getMessage(),
                ]);
                // Continue processing even if category indices fail
            }
        }

        // Process each feed item
        $this->io->progressStart(count($feedItems));

        foreach ($feedItems as $item) {
            $this->io->progressAdvance();

            try {
                $result = $this->processRssItem($item, $nzine, $categories, $isDryRun, $categoryIndices);

                if ($result === 'created') {
                    $stats['events_created']++;
                    $stats['items_matched']++;
                } elseif ($result === 'updated') {
                    $stats['events_updated']++;
                    $stats['items_matched']++;
                } elseif ($result === 'duplicate') {
                    $stats['items_skipped_duplicate']++;
                } elseif ($result === 'unmatched') {
                    $stats['items_skipped_unmatched']++;
                }
            } catch (\Exception $e) {
                $this->io->error(sprintf(
                    'Error processing RSS item "%s": %s',
                    $item['title'] ?? 'unknown',
                    $e->getMessage()
                ));
                $this->logger->error('Error processing RSS item', [
                    'nzine_id' => $nzine->getId(),
                    'item_title' => $item['title'] ?? 'unknown',
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        $this->io->progressFinish();

        // Re-sign all category indices after articles have been added
        if (!$isDryRun && !empty($categoryIndices)) {
            $this->io->text('Re-signing category indices...');
            try {
                $this->categoryIndexService->resignCategoryIndices($categoryIndices, $nzine);
                $this->io->text(sprintf('✓ Re-signed %d category indices', count($categoryIndices)));
            } catch (\Exception $e) {
                $this->io->warning(sprintf('Failed to re-sign category indices: %s', $e->getMessage()));
                $this->logger->error('Category index re-signing failed', [
                    'nzine_id' => $nzine->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Update last fetched timestamp
        if (!$isDryRun) {
            $nzine->setLastFetchedAt(new \DateTimeImmutable());
            $this->entityManager->flush();
        }

        $this->io->table(
            ['Metric', 'Count'],
            [
                ['Items fetched', $stats['items_fetched']],
                ['Items matched', $stats['items_matched']],
                ['Events created', $stats['events_created']],
                ['Events updated', $stats['events_updated']],
                ['Duplicates skipped', $stats['items_skipped_duplicate']],
                ['Unmatched skipped', $stats['items_skipped_unmatched']],
            ]
        );

        return $stats;
    }

    /**
     * Process a single RSS item
     *
     * @return string Result: 'created', 'duplicate', or 'unmatched'
     */
    private function processRssItem(array $item, $nzine, array $categories, bool $isDryRun, array $categoryIndices): string
    {
        // Generate slug for duplicate detection
        $slug = $this->rssToNostrConverter->generateSlugForItem($item);

        // Check if already exists
        $existing = $this->articleRepository->findOneBy(['slug' => $slug]);
        if ($existing) {
            if ($isDryRun) {
                $this->io->text(sprintf(
                    '  🔄 Would update: "%s"',
                    $item['title'] ?? 'unknown'
                ));
                return 'updated';
            }

            $this->io->text(sprintf(
                '  🔄 Updating existing article: "%s"',
                $item['title'] ?? 'unknown'
            ));
            $this->logger->debug('Found existing article - updating', [
                'slug' => $slug,
                'title' => $item['title'],
            ]);

            // Match to category for fresh data
            $matchedCategory = $this->tagMatchingService->findMatchingCategory(
                $item['categories'] ?? [],
                $categories
            );

            // Convert to Nostr event to get fresh data with all processing applied
            $nostrEvent = $this->rssToNostrConverter->convertToNostrEvent(
                $item,
                $matchedCategory,
                $nzine
            );

            // Add original RSS categories as additional tags
            if (!empty($item['categories'])) {
                foreach ($item['categories'] as $rssCategory) {
                    $categorySlug = strtolower(trim($rssCategory));
                    $tagExists = false;

                    foreach ($nostrEvent->getTags() as $existingTag) {
                        if (is_array($existingTag) && $existingTag[0] === 't' && isset($existingTag[1]) && $existingTag[1] === $categorySlug) {
                            $tagExists = true;
                            break;
                        }
                    }

                    if (!$tagExists) {
                        $nostrEvent->addTag(['t', $categorySlug]);
                    }
                }
            }

            // Convert to stdClass for processing
            $eventObject = json_decode($nostrEvent->toJson());

            // Update all fields from the fresh event data
            $existing->setContent($eventObject->content);
            $existing->setTitle($item['title'] ?? '');

            // Set createdAt and publishedAt from RSS pubDate if available
            if (isset($item['pubDate']) && $item['pubDate'] instanceof \DateTimeImmutable) {
                $existing->setCreatedAt($item['pubDate']);
                $existing->setPublishedAt($item['pubDate']);
            }

            // Extract and set image from tags
            foreach ($eventObject->tags as $tag) {
                if ($tag[0] === 'image' && isset($tag[1])) {
                    $existing->setImage($tag[1]);
                    break;
                }
            }

            // Extract and set summary from tags (now with HTML stripped)
            foreach ($eventObject->tags as $tag) {
                if ($tag[0] === 'summary' && isset($tag[1])) {
                    $existing->setSummary($tag[1]);
                    break;
                }
            }

            // Clear existing topics and re-add from fresh data
            $existing->clearTopics();
            foreach ($eventObject->tags as $tag) {
                if ($tag[0] === 't' && isset($tag[1])) {
                    $existing->addTopic($tag[1]);
                }
            }

            $this->entityManager->persist($existing);
            $this->entityManager->flush();

            $this->logger->info('Article updated with fresh RSS data', [
                'slug' => $slug,
                'title' => $item['title'],
            ]);

            return 'updated';
        }

        // Match to category
        $matchedCategory = $this->tagMatchingService->findMatchingCategory(
            $item['categories'] ?? [],
            $categories
        );

        if (!$matchedCategory) {
            $this->io->text(sprintf(
                '  ℹ No category match: "%s" [categories: %s] - importing as standalone',
                $item['title'] ?? 'unknown',
                implode(', ', $item['categories'] ?? ['none'])
            ));
            $this->logger->debug('No category match for item - importing as standalone', [
                'title' => $item['title'],
                'categories' => $item['categories'] ?? [],
            ]);
            // Don't return - continue processing without a category
        }

        if ($isDryRun) {
            $categoryLabel = $matchedCategory
                ? ($matchedCategory['name'] ?? $matchedCategory['title'] ?? $matchedCategory['slug'] ?? 'unknown')
                : 'standalone';

            $this->io->text(sprintf(
                '  ✓ Would create: "%s" → %s',
                $item['title'] ?? 'unknown',
                $categoryLabel
            ));
            $this->logger->info('[DRY RUN] Would create event', [
                'title' => $item['title'],
                'category' => $categoryLabel,
                'slug' => $slug,
            ]);
            return 'created';
        }

        // Convert to Nostr event (with or without category)
        $nostrEvent = $this->rssToNostrConverter->convertToNostrEvent(
            $item,
            $matchedCategory,
            $nzine
        );

        // Add original RSS categories as additional tags (topics)
        // This ensures RSS feed categories are preserved even if they don't match nzine categories
        if (!empty($item['categories'])) {
            foreach ($item['categories'] as $rssCategory) {
                // Add as 't' tag if not already present
                $categorySlug = strtolower(trim($rssCategory));
                $tagExists = false;

                foreach ($nostrEvent->getTags() as $existingTag) {
                    if (is_array($existingTag) && $existingTag[0] === 't' && isset($existingTag[1]) && $existingTag[1] === $categorySlug) {
                        $tagExists = true;
                        break;
                    }
                }

                if (!$tagExists) {
                    $nostrEvent->addTag(['t', $categorySlug]);
                }
            }
        }

        // Convert Nostr Event to stdClass object for ArticleFactory
        $eventObject = json_decode($nostrEvent->toJson());

        // Create Article entity from the event object
        $article = $this->articleFactory->createFromLongFormContentEvent($eventObject);
        $this->entityManager->persist($article);
        $this->entityManager->flush();

        // Add article to category index if category matched
        if ($matchedCategory && isset($matchedCategory['slug']) && !empty($categoryIndices)) {
            $categorySlug = $matchedCategory['slug'];
            if (isset($categoryIndices[$categorySlug])) {
                $articleCoordinate = sprintf(
                    '%d:%s:%s',
                    $article->getKind()->value,
                    $article->getPubkey(),
                    $article->getSlug()
                );

                try {
                    $this->categoryIndexService->addArticleToCategoryIndex(
                        $categoryIndices[$categorySlug],
                        $articleCoordinate,
                        $nzine
                    );
                    // Flush to ensure the category index is saved to the database
                    $this->entityManager->flush();

                    $this->logger->debug('Added article to category index', [
                        'article_slug' => $article->getSlug(),
                        'category_slug' => $categorySlug,
                        'coordinate' => $articleCoordinate,
                    ]);
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to add article to category index', [
                        'article_slug' => $article->getSlug(),
                        'category_slug' => $categorySlug,
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                $this->logger->warning('Category index not found for matched category', [
                    'category_slug' => $categorySlug,
                    'available_indices' => array_keys($categoryIndices),
                ]);
            }
        }

        $categoryLabel = $matchedCategory
            ? ($matchedCategory['name'] ?? $matchedCategory['title'] ?? $matchedCategory['slug'] ?? 'unknown')
            : 'standalone';

        $this->io->text(sprintf(
            '  ✓ Created: "%s" → %s',
            $item['title'] ?? 'unknown',
            $categoryLabel
        ));

        // Publish to relays (async/background in production)
        try {
            // TODO: Get configured relays from nzine or use default
            // $this->nostrClient->publishEvent($nostrEvent, $relays);
            $this->logger->info('Event created and saved', [
                'event_id' => $nostrEvent->getId() ?? 'unknown',
                'title' => $item['title'],
                'category' => $categoryLabel,
            ]);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to publish to relays', [
                'event_id' => $nostrEvent->getId() ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            // Continue even if relay publishing fails
        }

        return 'created';
    }
}

