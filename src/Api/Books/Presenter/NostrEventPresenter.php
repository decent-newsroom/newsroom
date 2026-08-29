<?php

declare(strict_types=1);

namespace App\Api\Books\Presenter;

use Psr\Log\LoggerInterface;

final class NostrEventPresenter
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /** @param array<string, mixed> $source
     *  @return array<string, mixed>|null
     */
    public function present(array $source, string $documentId = ''): ?array
    {
        $requiredStrings = ['content', 'id', 'pubkey', 'sig'];
        foreach ($requiredStrings as $field) {
            if (!isset($source[$field]) || !is_string($source[$field])) {
                return $this->malformed($documentId, $field);
            }
        }
        if (!isset($source['created_at']) || filter_var($source['created_at'], FILTER_VALIDATE_INT) === false) {
            return $this->malformed($documentId, 'created_at');
        }
        if (!isset($source['kind']) || filter_var($source['kind'], FILTER_VALIDATE_INT) === false) {
            return $this->malformed($documentId, 'kind');
        }
        if (!isset($source['tags']) || !is_array($source['tags']) || !array_is_list($source['tags'])) {
            return $this->malformed($documentId, 'tags');
        }

        foreach ($source['tags'] as $tag) {
            if (!is_array($tag) || !array_is_list($tag) || array_filter($tag, static fn (mixed $value): bool => !is_string($value)) !== []) {
                return $this->malformed($documentId, 'tags');
            }
        }

        return [
            'content' => $source['content'],
            'created_at' => (int) $source['created_at'],
            'id' => $source['id'],
            'kind' => (int) $source['kind'],
            'pubkey' => $source['pubkey'],
            'sig' => $source['sig'],
            'tags' => $source['tags'],
        ];
    }

    private function malformed(string $documentId, string $field): null
    {
        $this->logger->warning('Skipping malformed Books API Elasticsearch document', [
            'document_id' => $documentId,
            'field' => $field,
        ]);

        return null;
    }
}
