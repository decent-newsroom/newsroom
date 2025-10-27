<?php

declare(strict_types=1);

namespace App\Command;

use FOS\ElasticaBundle\Finder\FinderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Elastica\Query\BoolQuery;
use Elastica\Query;
use Elastica\Collapse;
use Elastica\Query\Terms;
use Psr\Cache\CacheItemPoolInterface;
use swentel\nostr\Key\Key;

#[AsCommand(name: 'app:cache_latest_articles', description: 'Cache the latest articles list')]
class CacheLatestArticlesCommand extends Command
{
    private FinderInterface $finder;
    private CacheItemPoolInterface $articlesCache;
    private ParameterBagInterface $params;

    public function __construct(FinderInterface $finder, CacheItemPoolInterface $articlesCache, ParameterBagInterface $params)
    {
        parent::__construct();
        $this->finder = $finder;
        $this->articlesCache = $articlesCache;
        $this->params = $params;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = $this->params->get('kernel.environment');
        $cacheKey = 'latest_articles_list_' . $env;
        $cacheItem = $this->articlesCache->getItem($cacheKey);

        $key = new Key();
        $excludedPubkeys = [
            $key->convertToHex('npub1etsrcjz24fqewg4zmjze7t5q8c6rcwde5zdtdt4v3t3dz2navecscjjz94'), // Bitcoin Magazine (News Bot)
            $key->convertToHex('npub1m7szwpud3jh2k3cqe73v0fd769uzsj6rzmddh4dw67y92sw22r3sk5m3ys'), // No Bullshit Bitcoin (News Bot)
            $key->convertToHex('npub13wke9s6njrmugzpg6mqtvy2d49g4d6t390ng76dhxxgs9jn3f2jsmq82pk'), // TFTC (News Bot)
            $key->convertToHex('npub10akm29ejpdns52ca082skmc3hr75wmv3ajv4987c9lgyrfynrmdqduqwlx'), // Discreet Log (News Bot)
            $key->convertToHex('npub13uvnw9qehqkds68ds76c4nfcn3y99c2rl9z8tr0p34v7ntzsmmzspwhh99'), // Batcoinz (Just annoying)
            $key->convertToHex('npub1fls5au5fxj6qj0t36sage857cs4tgfpla0ll8prshlhstagejtkqc9s2yl'), // AGORA Marketplace - feed 𝚋𝚘𝚝 (Just annoying)
            $key->convertToHex('npub1t5d8kcn0hu8zmt6dpkgatd5hwhx76956g7qmdzwnca6fzgprzlhqnqks86'), // NSFW
            $key->convertToHex('npub14l5xklll5vxzrf6hfkv8m6n2gqevythn5pqc6ezluespah0e8ars4279ss'), // LNgigs, job offers feed
        ];

        // if (!$cacheItem->isHit()) {
            $boolQuery = new BoolQuery();
            $boolQuery->addMustNot(new Terms('pubkey', $excludedPubkeys));

            $query = new Query($boolQuery);
            $query->setSize(50);
            $query->setSort(['createdAt' => ['order' => 'desc']]);

            $collapse = new Collapse();
            $collapse->setFieldname('slug');
            $query->setCollapse($collapse);

            $collapse2 = new Collapse();
            $collapse2->setFieldname('pubkey');
            $query->setCollapse($collapse2);

            $articles = $this->finder->find($query);

            $cacheItem->set($articles);
            $cacheItem->expiresAfter(3600); // Cache for 1 hour
            $this->articlesCache->save($cacheItem);
            $output->writeln('<info>Cached ' . count($articles) . ' articles.</info>');
//        } else {
//            $output->writeln('<comment>Cache already exists for key: ' . $cacheKey . '</comment>');
//        }

        return Command::SUCCESS;
    }
}
