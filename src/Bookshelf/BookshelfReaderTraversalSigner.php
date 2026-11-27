<?php

declare(strict_types=1);

namespace App\Bookshelf;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Integrity for reader occurrence paths, never an access grant. */
final readonly class BookshelfReaderTraversalSigner
{
    public function __construct(#[Autowire('%kernel.secret%')] private string $secret)
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('A framework secret is required for reader traversal signatures.');
        }
    }

    /** @param list<int> $path @param list<string> $revisions */
    public function sign(string $root, array $path, array $revisions): string
    {
        return hash_hmac('sha256', json_encode(
            ['bookshelf-reader-v1', $root, $path, $revisions],
            JSON_THROW_ON_ERROR,
        ), $this->secret);
    }

    /** @param list<int> $path @param list<string> $revisions */
    public function isValid(string $root, array $path, array $revisions, string $signature): bool
    {
        return preg_match('/^[0-9a-f]{64}$/D', $signature) === 1
            && hash_equals($this->sign($root, $path, $revisions), $signature);
    }

    /** @param list<int> $path @param list<string> $revisions */
    public function signChapter(string $root, array $path, array $revisions, int $position): string
    {
        return hash_hmac('sha256', json_encode(
            ['bookshelf-chapter-v1', $root, $path, $revisions, $position],
            JSON_THROW_ON_ERROR,
        ), $this->secret);
    }

    /** @param list<int> $path @param list<string> $revisions */
    public function isValidChapter(string $root, array $path, array $revisions, int $position, string $signature): bool
    {
        return preg_match('/^[0-9a-f]{64}$/D', $signature) === 1
            && hash_equals($this->signChapter($root, $path, $revisions, $position), $signature);
    }
}
