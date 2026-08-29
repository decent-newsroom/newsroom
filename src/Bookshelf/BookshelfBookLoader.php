<?php

declare(strict_types=1);

namespace App\Bookshelf;

use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryApiClient;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryApiException;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryBookService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Resolves books through Mercury first, then through this instance's Books API.
 */
final class BookshelfBookLoader
{
    public function __construct(
        private readonly MercuryBookService $mercuryBookService,
        private readonly HttpClientInterface $httpClient,
        private readonly string $localBooksApiBaseUrl,
    ) {
    }

    /**
     * @param array<int, array{type: 'a'|'e', coordinate: ?string, relay: ?string, eventId: ?string, pubkey: ?string}> $references
     * @return array<int, array<string, mixed>>
     */
    public function getBooksForReferences(array $references): array
    {
        try {
            return $this->mercuryBookService->getBooksForReferences($references);
        } catch (MercuryApiException) {
            $localService = new MercuryBookService(new MercuryApiClient(
                $this->httpClient,
                $this->localBooksApiBaseUrl,
            ));

            return $localService->getBooksForReferences($references);
        }
    }
}
