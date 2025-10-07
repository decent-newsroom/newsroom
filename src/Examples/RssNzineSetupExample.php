<?php
/**
 * Example script for setting up a Nzine with RSS feed
 *
 * This is a reference implementation showing how to configure a nzine
 * with RSS feed support. Adapt this to your needs (console command, controller, etc.)
 */

namespace App\Examples;

use App\Entity\Nzine;
use App\Repository\NzineRepository;
use Doctrine\ORM\EntityManagerInterface;

class RssNzineSetupExample
{
    public function __construct(
        private readonly NzineRepository $nzineRepository,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * Example: Configure an existing nzine with RSS feed
     */
    public function setupRssFeedForNzine(int $nzineId): void
    {
        $nzine = $this->nzineRepository->find($nzineId);

        if (!$nzine) {
            throw new \RuntimeException("Nzine not found: $nzineId");
        }

        // Set the RSS feed URL
        $nzine->setFeedUrl('https://example.com/feed.rss');

        // Configure categories with tags for RSS item matching
        $categories = [
            [
                'name' => 'Artificial Intelligence',
                'slug' => 'ai',
                'tags' => ['artificial-intelligence', 'machine-learning', 'AI', 'ML', 'deep-learning', 'neural-networks']
            ],
            [
                'name' => 'Blockchain & Crypto',
                'slug' => 'blockchain',
                'tags' => ['crypto', 'cryptocurrency', 'blockchain', 'bitcoin', 'ethereum', 'web3', 'defi', 'nft']
            ],
            [
                'name' => 'Programming',
                'slug' => 'programming',
                'tags' => ['programming', 'coding', 'development', 'software', 'javascript', 'python', 'rust', 'go']
            ],
            [
                'name' => 'Nostr Protocol',
                'slug' => 'nostr',
                'tags' => ['nostr', 'decentralized', 'social-media', 'protocol']
            ]
        ];

        $nzine->setMainCategories($categories);

        // Optional: Set custom feed configuration
        $nzine->setFeedConfig([
            'enabled' => true,
            'description' => 'Tech news aggregator',
            // Future options:
            // 'max_age_days' => 7,
            // 'fetch_full_content' => true,
        ]);

        $this->entityManager->flush();

        echo "RSS feed configured for nzine #{$nzineId}\n";
        echo "Feed URL: " . $nzine->getFeedUrl() . "\n";
        echo "Categories: " . count($categories) . "\n";
    }

    /**
     * Example: Create a new RSS-enabled nzine from scratch
     */
    public function createRssEnabledNzine(
        string $title,
        string $summary,
        string $feedUrl,
        array $categories
    ): Nzine {
        // Note: This is a simplified example. In practice, you should:
        // 1. Use NzineWorkflowService to create the bot and profile
        // 2. Create the main index
        // 3. Create nested indices
        // 4. Transition through the workflow states

        $nzine = new Nzine();
        $nzine->setFeedUrl($feedUrl);
        $nzine->setMainCategories($categories);

        // You would normally use the workflow service here:
        // $this->nzineWorkflowService->init($nzine);
        // $this->nzineWorkflowService->createProfile(...);
        // etc.

        $this->entityManager->persist($nzine);
        $this->entityManager->flush();

        return $nzine;
    }

    /**
     * Example: List all RSS-enabled nzines
     */
    public function listRssNzines(): void
    {
        $nzines = $this->nzineRepository->findActiveRssNzines();

        echo "RSS-enabled Nzines:\n";
        echo str_repeat("=", 80) . "\n";

        foreach ($nzines as $nzine) {
            echo sprintf(
                "ID: %d | Slug: %s | Feed: %s\n",
                $nzine->getId(),
                $nzine->getSlug() ?? 'N/A',
                $nzine->getFeedUrl()
            );

            $lastFetched = $nzine->getLastFetchedAt();
            if ($lastFetched) {
                echo "  Last fetched: " . $lastFetched->format('Y-m-d H:i:s') . "\n";
            }

            echo "  Categories: " . count($nzine->getMainCategories()) . "\n";
            echo "\n";
        }
    }

    /**
     * Example RSS feed URLs for testing
     */
    public static function getExampleFeeds(): array
    {
        return [
            'tech' => [
                'TechCrunch' => 'https://techcrunch.com/feed/',
                'Hacker News' => 'https://hnrss.org/newest',
                'Ars Technica' => 'https://feeds.arstechnica.com/arstechnica/index',
            ],
            'crypto' => [
                'CoinDesk' => 'https://www.coindesk.com/arc/outboundfeeds/rss/',
                'Bitcoin Magazine' => 'https://bitcoinmagazine.com/.rss/full/',
            ],
            'programming' => [
                'Dev.to' => 'https://dev.to/feed',
                'GitHub Blog' => 'https://github.blog/feed/',
            ]
        ];
    }
}

