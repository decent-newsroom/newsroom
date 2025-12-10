<?php

namespace App\Service\Search;

class UserSearchFactory
{
    public function __construct(
        private readonly ElasticsearchUserSearch $elasticsearchUserSearch,
        private readonly DatabaseUserSearch $databaseUserSearch,
        private readonly bool $elasticsearchEnabled
    ) {
    }

    public function create(): UserSearchInterface
    {
        return $this->elasticsearchEnabled
            ? $this->elasticsearchUserSearch
            : $this->databaseUserSearch;
    }
}

