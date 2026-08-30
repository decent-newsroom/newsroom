<?php

declare(strict_types=1);

namespace App\Bookshelf;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * Adapts the bare Books API response schema to the Mercury client's envelope.
 */
final class BooksApiMercuryHttpClient implements HttpClientInterface
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return new BooksApiMercuryResponse($this->httpClient->request($method, $url, $options));
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof ResponseInterface) {
            return $this->httpClient->stream($this->unwrap($responses), $timeout);
        }

        $unwrapped = [];
        foreach ($responses as $key => $response) {
            $unwrapped[$key] = $this->unwrap($response);
        }

        return $this->httpClient->stream($unwrapped, $timeout);
    }

    public function withOptions(array $options): static
    {
        return new self($this->httpClient->withOptions($options));
    }

    private function unwrap(ResponseInterface $response): ResponseInterface
    {
        return $response instanceof BooksApiMercuryResponse ? $response->inner() : $response;
    }
}
