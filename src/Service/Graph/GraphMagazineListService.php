<?php

declare(strict_types=1);

namespace App\Service\Graph;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Graph-backed replacement for MagazineRepository listing queries.
 *
 * Uses current_record + parsed_reference to find and classify
 * kind 30040 events as top-level magazines.
 */
class GraphMagazineListService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly GraphLookupService $graphLookup,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * List all top-level magazines with metadata.
     *
     * @return array<int, array{coord: string, event_id: string, pubkey: string, d_tag: string, title: ?string, summary: ?string, image: ?string}>
     */
    public function listAllMagazines(): array
    {
        return $this->queryMagazines(null);
    }

    /**
     * List magazines owned by a specific pubkey.
     *
     * @return array<int, array{coord: string, event_id: string, pubkey: string, d_tag: string, title: ?string, summary: ?string, image: ?string}>
     */
    public function listByPubkey(string $pubkey): array
    {
        return $this->queryMagazines(strtolower($pubkey));
    }

    /**
     * List all books (kind 30040 events referencing 30041 content sections).
     *
     * @return array<int, array{coord: string, event_id: string, pubkey: string, d_tag: string, title: ?string, summary: ?string, image: ?string}>
     */
    public function listAllBooks(): array
    {
        return $this->queryBooks(null);
    }

    /**
     * List books owned by a specific pubkey.
     *
     * @return array<int, array{coord: string, event_id: string, pubkey: string, d_tag: string, title: ?string, summary: ?string, image: ?string}>
     */
    public function listBooksByPubkey(string $pubkey): array
    {
        return $this->queryBooks(strtolower($pubkey));
    }

    /**
     * Count kind 30040 records in graph.
     */
    public function countMagazines(): int
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM current_record WHERE kind = 30040'
            );
        } catch (\Throwable $e) {
            $this->logger->warning('GraphMagazineListService: count failed', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * @return array<int, array{coord: string, event_id: string, pubkey: string, d_tag: string, title: ?string, summary: ?string, image: ?string}>
     */
    private function queryMagazines(?string $pubkey): array
    {
        try {
            $sql = 'SELECT coord, current_event_id, pubkey, d_tag FROM current_record WHERE kind = 30040';
            $params = [];

            if ($pubkey !== null) {
                $sql .= ' AND pubkey = :pubkey';
                $params['pubkey'] = $pubkey;
            }

            $sql .= ' ORDER BY current_created_at DESC';

            $records = $this->connection->fetchAllAssociative($sql, $params);
            if (empty($records)) {
                return [];
            }

            $eventIds = array_column($records, 'current_event_id');
            $eventRows = $this->graphLookup->fetchEventRows($eventIds);

            $magazines = [];
            foreach ($records as $record) {
                $eventRow = $eventRows[$record['current_event_id']] ?? null;
                if (!$this->isTopLevelMagazine($eventRow)) {
                    continue;
                }
                $meta = $this->extractMetadata($eventRow);
                $magazines[] = [
                    'coord' => $record['coord'],
                    'event_id' => $record['current_event_id'],
                    'pubkey' => $record['pubkey'],
                    'd_tag' => $record['d_tag'],
                    'slug' => $record['d_tag'],
                    'title' => $meta['title'],
                    'summary' => $meta['summary'],
                    'image' => $meta['image'],
                ];
            }
            return $magazines;
        } catch (\Throwable $e) {
            $this->logger->error('GraphMagazineListService: listing failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * @return array<int, array{coord: string, event_id: string, pubkey: string, d_tag: string, title: ?string, summary: ?string, image: ?string}>
     */
    private function queryBooks(?string $pubkey): array
    {
        try {
            $sql = 'SELECT coord, current_event_id, pubkey, d_tag FROM current_record WHERE kind = 30040';
            $params = [];

            if ($pubkey !== null) {
                $sql .= ' AND pubkey = :pubkey';
                $params['pubkey'] = $pubkey;
            }

            $sql .= ' ORDER BY current_created_at DESC';

            $records = $this->connection->fetchAllAssociative($sql, $params);
            if (empty($records)) {
                return [];
            }

            $eventIds = array_column($records, 'current_event_id');
            $eventRows = $this->graphLookup->fetchEventRows($eventIds);

            $books = [];
            foreach ($records as $record) {
                $eventRow = $eventRows[$record['current_event_id']] ?? null;
                if (!$this->isBook($eventRow)) {
                    continue;
                }
                $meta = $this->extractMetadata($eventRow);
                $books[] = [
                    'coord' => $record['coord'],
                    'event_id' => $record['current_event_id'],
                    'pubkey' => $record['pubkey'],
                    'd_tag' => $record['d_tag'],
                    'slug' => $record['d_tag'],
                    'title' => $meta['title'],
                    'summary' => $meta['summary'],
                    'image' => $meta['image'],
                ];
            }
            return $books;
        } catch (\Throwable $e) {
            $this->logger->error('GraphMagazineListService: listing failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * A top-level magazine is a kind 30040 event whose "a" tags
     * reference other kind 30040 events (sub-indices / sections).
     */
    private function isTopLevelMagazine(?array $eventRow): bool
    {
        if ($eventRow === null) {
            return false;
        }
        $tags = $this->parseTags($eventRow);

        foreach ($tags as $tag) {
            if (($tag[0] ?? '') === 'a' && isset($tag[1]) && str_starts_with($tag[1], '30040:')) {
                return true;
            }
        }
        return false;
    }

    /**
     * A book is a kind 30040 event whose "a" tags
     * reference kind 30041 events (content sections).
     */
    private function isBook(?array $eventRow): bool
    {
        if ($eventRow === null) {
            return false;
        }
        $tags = $this->parseTags($eventRow);

        foreach ($tags as $tag) {
            if (($tag[0] ?? '') === 'a' && isset($tag[1]) && str_starts_with($tag[1], '30041:')) {
                return true;
            }
        }
        return false;
    }

    private function extractMetadata(?array $eventRow): array
    {
        $title = null;
        $summary = null;
        $image = null;
        if ($eventRow === null) {
            return compact('title', 'summary', 'image');
        }
        $tags = $this->parseTags($eventRow);
        foreach ($tags as $tag) {
            $key = $tag[0] ?? '';
            $val = $tag[1] ?? null;
            match ($key) {
                'title' => $title ??= $val,
                'summary' => $summary ??= $val,
                'image' => $image ??= $val,
                default => null,
            };
        }
        return compact('title', 'summary', 'image');
    }

    private function parseTags(array $eventRow): array
    {
        $tags = $eventRow['tags'] ?? '[]';
        if (is_string($tags)) {
            $tags = json_decode($tags, true);
        }
        return is_array($tags) ? $tags : [];
    }
}

