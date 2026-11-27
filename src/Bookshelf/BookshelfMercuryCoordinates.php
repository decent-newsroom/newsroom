<?php

declare(strict_types=1);

namespace App\Bookshelf;

use DecentNewsroom\BookshelfBundle\Service\Mercury\Model\MercuryEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Reads enough of an exact Mercury coordinate to resolve equal-time revisions. */
final readonly class BookshelfMercuryCoordinates
{
    private const LIMIT = 16;

    public function __construct(
        private HttpClientInterface $http,
        private LoggerInterface $logger,
        #[Autowire('%bookshelf.mercury_api_base_url%')]
        private string $baseUrl,
    ) {}

    /** @return list<object> */
    public function find(int $kind, string $pubkey, string $identifier): array
    {
        if (trim($identifier) === '') {
            return [];
        }

        try {
            $payload = $this->http->request('POST', rtrim($this->baseUrl, '/') . '/api/events/filter', [
                'headers' => ['Accept' => 'application/json'],
                'json' => ['kinds' => [$kind], 'authors' => [$pubkey], '#d' => [$identifier], 'limit' => self::LIMIT],
                'timeout' => 2.0,
                'max_duration' => 2.0,
            ])->toArray();
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('Could not resolve a Bookshelf coordinate from Mercury.', ['exception' => $exception]);

            return [];
        }

        $rows = array_is_list($payload) ? $payload : ($payload['data'] ?? null);
        if (!is_array($rows) || !array_is_list($rows)) {
            return [];
        }

        // Mercury sorts by timestamp but does not specify an ID tiebreaker.
        // A full batch is safe only when it includes an older timestamp.
        if (count($rows) >= self::LIMIT) {
            $newest = $rows[0]['created_at'] ?? null;
            $oldest = $rows[array_key_last($rows)]['created_at'] ?? null;
            if (!is_int($newest) || !is_int($oldest) || $oldest >= $newest) {
                return [];
            }
        }

        $events = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            try {
                $events[] = (object) MercuryEvent::fromArray($row)->toArray();
            } catch (\InvalidArgumentException $exception) {
                $this->logger->warning('Skipping an invalid Bookshelf event returned by Mercury.', ['exception' => $exception]);
            }
        }

        return $events;
    }
}
