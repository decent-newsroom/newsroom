<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Article;
use App\Enum\KindsEnum;
use App\ReadModel\RedisView\RedisViewFactory;
use App\Repository\ArticleRepository;
use App\Service\Cache\RedisCacheService;
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
        private readonly RedisCacheService $redisCacheService,
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
            'Target number of human articles to cache (will fetch a larger pool and filter bots)',
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

        $qb = $this->articleRepository->createQueryBuilder('a');
        $qb->where('a.publishedAt IS NOT NULL')
            ->andWhere('a.slug IS NOT NULL')
            ->andWhere("a.slug != ''")
            ->andWhere('a.title IS NOT NULL')
            ->andWhere("a.title != ''")
            ->andWhere('a.kind != :draftKind')
            ->setParameter('draftKind', KindsEnum::LONGFORM_DRAFT);

        if (!empty($excludedPubkeys)) {
            $qb->andWhere($qb->expr()->notIn('a.pubkey', ':excludedPubkeys'))
                ->setParameter('excludedPubkeys', $excludedPubkeys);
        }

        $qb->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($fetchLimit);

        /** @var Article[] $allArticles */
        $allArticles = $qb->getQuery()->getResult();

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

        // Collect ALL author pubkeys for metadata fetching (needed for bot detection)
        $authorPubkeys = array_keys($articlesByAuthor);

        $output->writeln(sprintf('<comment>Fetching metadata for %d authors...</comment>', count($authorPubkeys)));
        $authorsMetadata = $this->redisCacheService->getMultipleMetadata($authorPubkeys);
        $output->writeln(sprintf('<info>✓ Fetched %d author profiles</info>', count($authorsMetadata)));

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
            $output->writeln(sprintf('<comment>Excluded %d bot/RSS articles from latest feed</comment>', $excludedCount));
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
        $output->writeln(sprintf('<info>  (target: %d, excluded %d bots from %d unique authors)</info>', $target, $excludedCount, count($articlesByAuthor)));
        $output->writeln('<info>  Key: view:articles:latest</info>');

        return Command::SUCCESS;
    }
}
