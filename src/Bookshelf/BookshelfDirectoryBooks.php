<?php

declare(strict_types=1);

namespace App\Bookshelf;

use App\Service\EventWorkspace\EventWorkspaceScopePolicy;
use App\Service\Nostr\RelayEndpoint;
use App\Service\Nostr\RelayPoolInterface;
use App\Service\Nostr\RelayQueryRequest;
use App\Service\Nostr\RelayRegistry;
use App\Service\Nostr\RelaySet;
use DecentNewsroom\BookshelfBundle\Service\Mercury\Exception\MercuryApiException;
use DecentNewsroom\BookshelfBundle\Service\Mercury\MercuryApiClientInterface;
use DecentNewsroom\BookshelfBundle\Service\Mercury\Model\MercuryEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @phpstan-type BookReference array{type: 'a'|'e', coordinate: ?string, relay: ?string, eventId: ?string, pubkey: ?string}
 */
final readonly class BookshelfDirectoryBooks
{
    // The API's combined author/d filter can match one coordinate per pair.
    public const PAGE_SIZE = 10;
    private const KIND = 30040;
    private const LOCAL_RELAY_TIMEOUT = 2;

    public function __construct(
        #[Autowire(service: 'bookshelf.publication_lookup.books_client')]
        private MercuryApiClientInterface $booksApi,
        #[Autowire(service: 'bookshelf.publication_lookup.mercury_client')]
        private MercuryApiClientInterface $mercury,
        private RelayRegistry $relayRegistry,
        private RelayPoolInterface $relayPool,
        private BookshelfRelayBookLoader $bookLoader,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<BookReference> $references
     * @return array{books: list<array<string, mixed>>, missing: int, available: bool}
     */
    public function resolve(array $references): array
    {
        if (count($references) > self::PAGE_SIZE) {
            throw new \InvalidArgumentException('Resolve one Bookshelf directory page at a time.');
        }

        $resolved = [];
        $failed = false;
        foreach (['Books API' => $this->booksApi, 'Mercury' => $this->mercury] as $source => $api) {
            $remaining = array_diff_key($references, $resolved);
            if ($remaining === []) {
                break;
            }

            $exact = array_filter($remaining, static fn (array $reference): bool => $reference['eventId'] !== null);
            $current = array_diff_key($remaining, $exact);
            $identifiersByAuthor = $this->identifiersByAuthor($current);
            $identifiers = array_values(array_unique(array_filter(
                array_merge(...array_values($identifiersByAuthor)),
                static fn (string $identifier): bool => trim($identifier) !== '',
            )));

            try {
                if ($exact !== []) {
                    $ids = array_values(array_unique(array_column($exact, 'eventId')));
                    $resolved += $this->match($exact, $api->getPublicationEventsByIds($ids));
                }
                if ($identifiers !== []) {
                    $authors = array_keys($identifiersByAuthor);
                    $resolved += $this->match($current, $api->getPublicationsByAuthors(
                        $authors, count($authors) * count($identifiers), $identifiers,
                    ));
                }
            } catch (MercuryApiException $exception) {
                $failed = true;
                $this->logger->warning('My Books publication lookup failed.', [
                    'source' => $source, 'error' => $exception->getMessage(),
                ]);
            }
        }

        $remaining = array_diff_key($references, $resolved);
        if ($remaining !== [] && ($localRelay = $this->relayRegistry->getLocalRelay()) !== null) {
            $local = $this->queryLocalRelay($localRelay, $remaining);
            $resolved += $this->match($remaining, $local['events']);
            $failed = $failed || $local['failed'];
        }

        $books = [];
        $matched = 0;
        foreach ($references as $position => $reference) {
            $event = $resolved[$position] ?? null;
            if ($event === null || EventWorkspaceScopePolicy::isScopedInventoryList(self::KIND, $event->tags)) {
                continue;
            }
            $card = $this->bookLoader->cardFromEvent((object) $event->toArray(), $reference['relay']);
            if ($card !== null) {
                $books[$card['coordinate']] ??= $card;
                ++$matched;
            }
        }

        return [
            'books' => array_values($books),
            'missing' => count($references) - $matched,
            'available' => !$failed || $books !== [],
        ];
    }

    /** @param array<int, BookReference> $references @return array<string, list<string>> */
    private function identifiersByAuthor(array $references): array
    {
        $identifiersByAuthor = [];
        foreach ($references as $reference) {
            if ($reference['eventId'] === null && $reference['coordinate'] !== null) {
                [, $author, $identifier] = explode(':', $reference['coordinate'], 3);
                $identifiersByAuthor[$author][] = $identifier;
            }
        }

        return $identifiersByAuthor;
    }

    /**
     * @param array<int, BookReference> $references
     * @return array{events: list<MercuryEvent>, failed: bool}
     */
    private function queryLocalRelay(string $url, array $references): array
    {
        $filters = [];
        $ids = array_values(array_unique(array_filter(array_column($references, 'eventId'), 'is_string')));
        if ($ids !== []) {
            $filters[] = ['kinds' => [self::KIND], 'ids' => $ids, 'limit' => count($ids)];
        }
        foreach ($this->identifiersByAuthor($references) as $author => $identifiers) {
            $filters[] = ['kinds' => [self::KIND], 'authors' => [$author], '#d' => $identifiers, 'limit' => count($identifiers)];
        }

        try {
            $request = new RelayQueryRequest(new RelaySet([new RelayEndpoint($url)]), $filters);
            $results = $this->relayPool->executeRequest($request->setTimeout(self::LOCAL_RELAY_TIMEOUT));
        } catch (\Throwable $exception) {
            $this->logger->warning('My Books local relay lookup failed.', ['exception' => $exception]);

            return ['events' => [], 'failed' => true];
        }

        $events = [];
        $failed = $results === [];
        foreach ($results as $result) {
            if (!$result->eose || $result->error !== null) {
                $failed = true;
                $this->logger->warning('My Books local relay lookup did not finish.', ['error' => $result->error]);
            }
            foreach ($result->events as $event) {
                try {
                    $events[] = MercuryEvent::fromArray($event->toArray());
                } catch (\InvalidArgumentException $exception) {
                    $this->logger->warning('My Books received a malformed local event.', ['error' => $exception->getMessage()]);
                }
            }
        }

        return ['events' => $events, 'failed' => $failed];
    }

    /**
     * @param array<int, BookReference> $references
     * @param list<MercuryEvent> $events
     * @return array<int, MercuryEvent>
     */
    private function match(array $references, array $events): array
    {
        $exact = $latest = $coordinates = [];
        foreach ($events as $event) {
            if ($event->kind !== self::KIND) {
                continue;
            }
            foreach ($event->tags as $tag) {
                if (($tag[0] ?? null) !== 'd') {
                    continue;
                }
                if (isset($tag[1]) && $tag[1] !== '') {
                    $coordinate = self::KIND . ':' . $event->pubkey . ':' . $tag[1];
                    $exact[$event->id] = $event;
                    $coordinates[$event->id] = $coordinate;
                    $current = $latest[$coordinate] ?? null;
                    if ($current === null || $event->createdAt > $current->createdAt
                        || ($event->createdAt === $current->createdAt && strcmp($event->id, $current->id) < 0)) {
                        $latest[$coordinate] = $event;
                    }
                }
                break;
            }
        }

        $matched = [];
        foreach ($references as $position => $reference) {
            $id = $reference['eventId'];
            $coordinate = $reference['coordinate'];
            if ($id !== null) {
                if (isset($exact[$id]) && ($coordinate === null || $coordinates[$id] === $coordinate)) {
                    $matched[$position] = $exact[$id];
                }
            } elseif ($coordinate !== null && isset($latest[$coordinate])) {
                $matched[$position] = $latest[$coordinate];
            }
        }

        return $matched;
    }
}
