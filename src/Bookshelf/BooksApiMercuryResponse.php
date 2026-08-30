<?php

declare(strict_types=1);

namespace App\Bookshelf;

use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Adds Mercury's `data` envelope only when decoding the local Books API body.
 */
final class BooksApiMercuryResponse implements ResponseInterface
{
    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    public function getHeaders(bool $throw = true): array
    {
        return $this->response->getHeaders($throw);
    }

    public function getContent(bool $throw = true): string
    {
        return $this->response->getContent($throw);
    }

    public function toArray(bool $throw = true): array
    {
        $payload = $this->response->toArray($throw);

        return array_key_exists('data', $payload) ? $payload : ['data' => $payload];
    }

    public function cancel(): void
    {
        $this->response->cancel();
    }

    public function getInfo(?string $type = null): mixed
    {
        return $this->response->getInfo($type);
    }

    public function inner(): ResponseInterface
    {
        return $this->response;
    }
}
