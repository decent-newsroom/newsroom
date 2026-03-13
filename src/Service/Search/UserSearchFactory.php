<?php

namespace App\Service\Search;

use App\Service\Nostr\Nip05VerificationService;
use Psr\Log\LoggerInterface;

class UserSearchFactory
{
    public function __construct(
        private readonly ElasticsearchUserSearch $elasticsearchUserSearch,
        private readonly DatabaseUserSearch $databaseUserSearch,
        private readonly Nip05VerificationService $nip05VerificationService,
        private readonly LoggerInterface $logger,
        private readonly bool $elasticsearchEnabled,
    ) {
    }

    public function create(): UserSearchInterface
    {
        $inner = $this->elasticsearchEnabled
            ? $this->elasticsearchUserSearch
            : $this->databaseUserSearch;

        return new Nip05AwareUserSearch($inner, $this->nip05VerificationService, $this->logger);
    }
}

