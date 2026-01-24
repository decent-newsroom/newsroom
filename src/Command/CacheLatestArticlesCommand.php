<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Article;
use App\ReadModel\RedisView\RedisViewFactory;
use App\Repository\ArticleRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Cache\RedisViewStore;
use swentel\nostr\Key\Key;
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

        // Define excluded pubkeys (bots, spam accounts)
        $key = new Key();
        $excludedPubkeys = [
            $key->convertToHex('npub1etsrcjz24fqewg4zmjze7t5q8c6rcwde5zdtdt4v3t3dz2navecscjjz94'), // Bitcoin Magazine (News Bot)
            $key->convertToHex('npub1m7szwpud3jh2k3cqe73v0fd769uzsj6rzmddh4dw67y92sw22r3sk5m3ys'), // No Bullshit Bitcoin (News Bot)
            $key->convertToHex('npub13wke9s6njrmugzpg6mqtvy2d49g4d6t390ng76dhxxgs9jn3f2jsmq82pk'), // TFTC (News Bot)
            $key->convertToHex('npub10akm29ejpdns52ca082skmc3hr75wmv3ajv4987c9lgyrfynrmdqduqwlx'), // Discreet Log (News Bot)
            $key->convertToHex('npub13uvnw9qehqkds68ds76c4nfcn3y99c2rl9z8tr0p34v7ntzsmmzspwhh99'), // Batcoinz
            $key->convertToHex('npub1fls5au5fxj6qj0t36sage857cs4tgfpla0ll8prshlhstagejtkqc9s2yl'), // AGORA Marketplace
            $key->convertToHex('npub1t5d8kcn0hu8zmt6dpkgatd5hwhx76956g7qmdzwnca6fzgprzlhqnqks86'), // NSFW
            $key->convertToHex('npub14l5xklll5vxzrf6hfkv8m6n2gqevythn5pqc6ezluespah0e8ars4279ss'), // LNgigs
            $key->convertToHex('npub1sztw66ap7gdrwyaag7js8m3h0vxw9uv8k0j68t5sngjmsjgpqx9sm9wyaq'), // Now Playing bot
        ];

        $output->writeln('<comment>Querying database for latest articles...</comment>');

        // Query database directly - get latest published articles, one per author
        // Using DQL to get the most recent article per pubkey
        $qb = $this->articleRepository->createQueryBuilder('a');
        $qb->where('a.publishedAt IS NOT NULL')
            ->andWhere('a.slug IS NOT NULL')
            ->andWhere('a.title IS NOT NULL')
            ->andWhere($qb->expr()->notIn('a.pubkey', ':excludedPubkeys'))
            ->setParameter('excludedPubkeys', $excludedPubkeys)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit * 2); // Get more initially, will dedupe by author

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

            // Keep first (most recent) article per author
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

        // Build Redis view objects - we now have exact Article entities!
        $output->writeln('<comment>Building Redis view objects...</comment>');
        $baseObjects = [];

        foreach ($articles as $article) {
            $authorMeta = $authorsMetadata[$article->getPubkey()] ?? null;

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
