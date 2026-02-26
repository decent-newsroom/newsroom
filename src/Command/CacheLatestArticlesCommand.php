<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Article;
use App\ReadModel\RedisView\RedisViewFactory;
use App\Repository\ArticleRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Cache\RedisViewStore;
use App\Service\LatestArticles\LatestArticlesExclusionPolicy;
use App\Service\MutedPubkeysService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:cache_latest_articles',
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
        private readonly MutedPubkeysService $mutedPubkeysService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit',
            'l',
            InputOption::VALUE_OPTIONAL,
            'Maximum number of articles to cache',
            50
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');

        $output->writeln('<comment>Querying database for latest articles...</comment>');

        // Muted pubkeys are always excluded from latest feeds.
        $excludedPubkeys = $this->mutedPubkeysService->getMutedPubkeys();

        // Query database directly - get latest published articles, one per author
        $qb = $this->articleRepository->createQueryBuilder('a');
        $qb->where('a.publishedAt IS NOT NULL')
            ->andWhere('a.slug IS NOT NULL')
            ->andWhere('a.title IS NOT NULL');

        if (!empty($excludedPubkeys)) {
            $qb->andWhere($qb->expr()->notIn('a.pubkey', ':excludedPubkeys'))
                ->setParameter('excludedPubkeys', $excludedPubkeys);
        }

        $qb->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit * 3); // fetch more to allow exclusions + dedupe

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

        // Take only the requested limit
        $articles = array_slice($articlesByAuthor, 0, $limit);

        $output->writeln(sprintf('<info>Selected %d articles (one per author)</info>', count($articles)));

        if (empty($articles)) {
            $output->writeln('<error>No articles found matching criteria</error>');
            return Command::FAILURE;
        }

        // Collect author pubkeys for metadata fetching
        $authorPubkeys = array_keys($articlesByAuthor);

        $output->writeln(sprintf('<comment>Fetching metadata for %d authors...</comment>', count($authorPubkeys)));
        $authorsMetadata = $this->redisCacheService->getMultipleMetadata($authorPubkeys);
        $output->writeln(sprintf('<info>✓ Fetched %d author profiles</info>', count($authorsMetadata)));

        // Build Redis view objects
        $output->writeln('<comment>Building Redis view objects...</comment>');
        $baseObjects = [];
        $excludedCount = 0;

        foreach ($articles as $article) {
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
        }

        if ($excludedCount > 0) {
            $output->writeln(sprintf('<comment>Excluded %d bot/RSS articles from latest feed</comment>', $excludedCount));
        }

        if (empty($baseObjects)) {
            $output->writeln('<error>No view objects created</error>');
            return Command::FAILURE;
        }

        // Store to Redis views
        $output->writeln('<comment>Storing to Redis...</comment>');
        $this->viewStore->storeLatestArticles($baseObjects);

        $output->writeln('');
        $output->writeln(sprintf('<info>✓ Successfully cached %d articles to Redis views</info>', count($baseObjects)));
        $output->writeln('<info>  Key: view:articles:latest</info>');

        return Command::SUCCESS;
    }
}
