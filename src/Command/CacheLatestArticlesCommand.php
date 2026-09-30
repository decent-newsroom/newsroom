<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\UserMetadata;
use App\Entity\Article;
use App\ReadModel\RedisView\RedisViewFactory;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Service\Cache\RedisViewStore;
use App\Service\LatestArticles\LatestArticlesExclusionPolicy;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:cache-latest-articles',
    description: 'Cache the latest articles list to Redis views'
)]
class CacheLatestArticlesCommand extends Command
{
    public function __construct(
        private readonly ArticleRepository $articleRepository,
        private readonly EventRepository $eventRepository,
        private readonly RedisViewStore $viewStore,
        private readonly RedisViewFactory $viewFactory,
        private readonly LatestArticlesExclusionPolicy $exclusionPolicy,
    ) {
        parent::__construct();
    }

    private const TARGET_ARTICLES = 20;

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            'l',
            InputOption::VALUE_OPTIONAL,
            'Target number of human articles to cache (will fetch a larger pool and filter bots/spam)',
            self::TARGET_ARTICLES
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = (int) $input->getOption('limit');

        $output->writeln('<comment>Querying database for latest articles...</comment>');

        // Unified exclusion: config-level deny-list + admin-muted users,
        // applied at the DB-query level so excluded authors never consume
        // the row budget.
        $excludedPubkeys = $this->exclusionPolicy->getAllExcludedPubkeys();

        if (!empty($excludedPubkeys)) {
            $output->writeln(sprintf('<info>Excluding %d pubkeys from initial query (muted + config deny-list)</info>', count($excludedPubkeys)));
        }

        // Fetch a large initial pool — most authors are bots/RSS, so we need
        // many more candidates than the target to end up with enough human articles.
        $fetchLimit = max($target * 15, 500);

        /** @var Article[] $allArticles */
        $allArticles = $this->articleRepository->findLatestForRecentFeed($fetchLimit, $excludedPubkeys);

        $output->writeln(sprintf('<info>Found %d articles from database</info>', count($allArticles)));

        // Deduplicate: Keep only the most recent article per author
        $articlesByAuthor = [];
        foreach ($allArticles as $article) {
            $pubkey = $article->getPubkey();
            if (!$pubkey) {
                continue;
            }

            if (!isset($articlesByAuthor[$pubkey])) {
                $articlesByAuthor[$pubkey] = $article;
            }
        }

        $output->writeln(sprintf('<info>Found %d unique authors</info>', count($articlesByAuthor)));

        if (empty($articlesByAuthor)) {
            $output->writeln('<error>No articles found matching criteria</error>');
            return Command::FAILURE;
        }

        // Resolve only persisted kind:0 events. The latest feed must never
        // trigger profile hydration for authors that are not known locally.
        $authorPubkeys = array_keys($articlesByAuthor);
        $metadataEvents = $this->eventRepository->findLatestMetadataByPubkeys($authorPubkeys);
        $authorsMetadata = [];
        foreach ($metadataEvents as $pubkey => $event) {
            $authorsMetadata[$pubkey] = UserMetadata::fromMetadataEvent($event);
        }

        $articlesByAuthor = array_filter(
            $articlesByAuthor,
            static fn (Article $article): bool => isset($authorsMetadata[$article->getPubkey()]),
        );

        $output->writeln(sprintf(
            '<info>✓ Found persisted metadata for %d authors</info>',
            count($authorsMetadata),
        ));

        // Filter bots FIRST, then take the target number of human articles
        $output->writeln('<comment>Filtering bots and building Redis view objects...</comment>');
        $baseObjects = [];
        $excludedCount = 0;

        foreach ($articlesByAuthor as $article) {
            $authorMeta = $authorsMetadata[$article->getPubkey()] ?? null;

            if ($this->exclusionPolicy->shouldExclude($article, $authorMeta)) {
                $excludedCount++;
                continue;
            }

            try {
                $baseObject = $this->viewFactory->articleBaseObject($article, $authorMeta);
                $baseObjects[] = $baseObject;
            } catch (\Exception $e) {
                $output->writeln(sprintf(
                    '<error>Failed to build view object for article %s: %s</error>',
                    $article->getSlug(),
                    $e->getMessage()
                ));
            }

            // Stop once we have enough human articles
            if (count($baseObjects) >= $target) {
                break;
            }
        }

        if ($excludedCount > 0) {
            $output->writeln(sprintf('<comment>Excluded %d bot/RSS/promotional articles from latest feed</comment>', $excludedCount));
        }

        if (empty($baseObjects)) {
            $output->writeln('<error>No human articles found after filtering — all authors are bots!</error>');
            return Command::FAILURE;
        }

        // Store to Redis views
        $output->writeln('<comment>Storing to Redis...</comment>');
        $this->viewStore->storeLatestArticles($baseObjects);

        $output->writeln('');
        $output->writeln(sprintf('<info>✓ Successfully cached %d articles to Redis views</info>', count($baseObjects)));
        $output->writeln(sprintf('<info>  (target: %d, excluded %d filtered articles from %d unique authors)</info>', $target, $excludedCount, count($articlesByAuthor)));
        $output->writeln('<info>  Key: view:articles:latest:v2</info>');

        return Command::SUCCESS;
    }
}
