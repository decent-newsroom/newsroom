<?php

declare(strict_types=1);

namespace App\Tests\Service\Api\Books;

use App\Api\Books\Dto\PublicationRecommendationRequest;
use App\Api\Books\Elasticsearch\BooksIndex;
use App\Api\Books\Elasticsearch\EventQueryBuilder;
use App\Api\Books\Elasticsearch\RecommendationQueryBuilder;
use App\Api\Books\Presenter\NostrEventPresenter;
use App\Api\Books\Service\PublicationRecommendationService;
use Elastica\Client;
use Elastica\Document;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Opt-in: BOOKS_TEST_ELASTICSEARCH_URL must point to a disposable test cluster.
 * Creates and removes only a randomly named books-recommendation-test-* index.
 */
final class PublicationRecommendationElasticsearchTest extends TestCase
{
    public function testRecommendationsAgainstTheBooksKeywordMapping(): void
    {
        $url = $_SERVER['BOOKS_TEST_ELASTICSEARCH_URL'] ?? getenv('BOOKS_TEST_ELASTICSEARCH_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('Set BOOKS_TEST_ELASTICSEARCH_URL to a disposable Elasticsearch test cluster.');
        }

        $client = new Client(['url' => rtrim($url, '/').'/', 'timeout' => 10]);
        $index = $client->getIndex('books-recommendation-test-'.bin2hex(random_bytes(8)));
        $index->create([
            'settings' => ['number_of_shards' => 1, 'number_of_replicas' => 0],
            'mappings' => [
                'dynamic_templates' => [['strings' => ['match_mapping_type' => 'string', 'mapping' => ['type' => 'keyword']]]],
                'properties' => [
                    'content' => ['type' => 'text'],
                    'created_at' => ['type' => 'date', 'format' => 'epoch_second'],
                    'id' => ['type' => 'keyword'],
                    'kind' => ['type' => 'long'],
                    'pubkey' => ['type' => 'keyword'],
                    'sig' => ['type' => 'keyword'],
                    'tags' => ['type' => 'object', 'enabled' => false],
                    'tags_flat' => ['properties' => [
                        't' => ['type' => 'keyword'],
                        'N' => ['type' => 'keyword'],
                        'T' => ['type' => 'keyword'],
                        'd' => ['type' => 'keyword'],
                    ]],
                ],
            ],
        ]);

        try {
            $documents = [
                $this->book(1, 'seed', ['Science fiction'], ['Author A']),
                $this->book(2, 'both', ['Science fiction'], ['Author A']),
                $this->book(3, 'subject', ['Science fiction'], ['Author B']),
                $this->book(4, 'author', ['History'], ['Author A']),
                $this->book(5, 'unrelated', ['Gardening'], ['Author C']),
                $this->book(6, 'excluded', ['Science fiction'], ['Author A']),
                $this->book(7, 'seed', ['Science fiction'], ['Author A']),
                $this->book(8, 'both', ['Science fiction'], ['Author A']),
                $this->book(9, 'chapter', ['Science fiction'], ['Author A'], 30041),
                $this->book(10, 'empty', [], []),
                $this->book(11, 'different-keyword', ['Science fiction -- History'], ['Author C']),
                $this->book(12, 'author-only-seed', [], ['Author A']),
            ];
            foreach ($documents as $position => $document) {
                // Deliberately different from the Nostr event ID.
                $index->addDocument(new Document('document-'.$position, $document));
            }
            $index->refresh();

            $logger = new NullLogger();
            $service = new PublicationRecommendationService(
                new BooksIndex($client, $logger, $index->getName()),
                new EventQueryBuilder(),
                new RecommendationQueryBuilder(),
                new NostrEventPresenter($logger),
            );
            $events = $service->recommend(PublicationRecommendationRequest::fromArray([
                'seed_event_id' => $this->id(1),
                'exclude_ids' => [$this->id(6), $this->id(12)],
                'limit' => 10,
            ]));
            self::assertSame([$this->id(2), $this->id(3), $this->id(4)], array_column($events, 'id'));
            self::assertSame(['content', 'created_at', 'id', 'kind', 'pubkey', 'sig', 'tags'], array_keys($events[0]));

            self::assertSame([], $service->recommend(PublicationRecommendationRequest::fromArray([
                'seed_event_id' => $this->id(10),
            ])));
            $authorResults = $service->recommend(PublicationRecommendationRequest::fromArray([
                'seed_event_id' => $this->id(12),
            ]));
            self::assertNotEmpty($authorResults);
            foreach ($authorResults as $event) {
                self::assertContains(['N', 'Author A'], $event['tags']);
                self::assertSame(30040, $event['kind']);
            }
        } finally {
            $index->delete();
        }
    }

    /** @param list<string> $subjects @param list<string> $authors
     *  @return array<string, mixed>
     */
    private function book(int $id, string $d, array $subjects, array $authors, int $kind = 30040): array
    {
        $tags = [['d', $d]];
        foreach ($subjects as $subject) {
            $tags[] = ['t', $subject];
        }
        foreach ($authors as $author) {
            $tags[] = ['N', $author];
        }

        return [
            'id' => $this->id($id), 'kind' => $kind, 'pubkey' => str_repeat('a', 64),
            'sig' => str_repeat('b', 128), 'created_at' => 1780000000, 'content' => '',
            'tags' => $tags, 'tags_flat' => ['d' => $d, 't' => $subjects, 'N' => $authors],
        ];
    }

    private function id(int $id): string
    {
        return str_pad(dechex($id), 64, '0', STR_PAD_LEFT);
    }
}
