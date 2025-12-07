<?php

namespace App\Service\Search;

use Psr\Log\LoggerInterface;

class ArticleSearchFactory
{
    public function __construct(
        private readonly ElasticsearchArticleSearch $elasticsearchSearch,
        private readonly DatabaseArticleSearch $databaseSearch,
        private readonly LoggerInterface $logger,
        private readonly bool $elasticsearchEnabled
    ) {
    }

    public function create(): ArticleSearchInterface
    {
        if ($this->elasticsearchEnabled && $this->elasticsearchSearch->isAvailable()) {
            $this->logger->info('Using Elasticsearch for article search');
            return $this->elasticsearchSearch;
        }

        $this->logger->info('Using database for article search');
        return $this->databaseSearch;
    }
}

