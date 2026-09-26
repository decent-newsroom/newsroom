<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api\Books;

use App\Api\Books\Controller\PublicationController;
use App\Api\Books\Elasticsearch\BooksIndex;
use App\Api\Books\Elasticsearch\EventQueryBuilder;
use App\Api\Books\Elasticsearch\PublicationQueryBuilder;
use App\Api\Books\Elasticsearch\RecommendationQueryBuilder;
use App\Api\Books\Elasticsearch\SectionQueryBuilder;
use App\Api\Books\Http\RequestDecoder;
use App\Api\Books\Presenter\NostrEventPresenter;
use App\Api\Books\Service\PublicationRecommendationService;
use Elastica\Client;
use Elastica\Index;
use Elastica\Result;
use Elastica\ResultSet;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

final class PublicationRecommendationServiceTest extends TestCase
{
    private array $queries = [];

    public function testEndpointReturnsRankedSignedEventsAndRemovesDuplicates(): void
    {
        $seed = $this->event('a', [['d', 'seed'], ['t', 'Science fiction'], ['N', 'Author']]);
        $first = $this->event('b', [['d', 'other']]);
        $withoutCoordinate = $this->event('c', []);
        $malformed = $this->event('d', []);
        unset($malformed['sig']);
        $controller = $this->controller([
            [$seed],
            [
                $seed,
                $this->event('e', [['d', 'seed']]),
                $malformed,
                $this->event('f', [], 30041),
                $first,
                $this->event('1', [['d', 'other']]),
                $this->event('2', []),
                $withoutCoordinate,
                $withoutCoordinate,
                $this->event('3', []),
            ],
        ]);
        $response = $controller->recommendations($this->request(['exclude_ids' => [str_repeat('2', 64)], 'limit' => 2]));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([$first, $withoutCoordinate], json_decode($response->getContent(), true));
        self::assertSame(str_repeat('a', 64), $this->queries[0]['query']['term']['id']['value']);
        self::assertSame(6, $this->queries[1]['size']);
    }

    public function testMetadataIsBoundedDeduplicatedAndPreservedExactly(): void
    {
        $tags = [['t', ' '], ['t', ' Exact subject '], ['t', ' Exact subject '], ['t'], ['N', '']];
        for ($i = 0; $i < 70; ++$i) {
            $tags[] = ['t', 'Subject '.$i];
            $tags[] = ['N', 'Author '.$i];
        }
        $controller = $this->controller([[$this->event('a', $tags)], []]);
        self::assertSame(200, $controller->recommendations($this->request())->getStatusCode());
        $should = $this->queries[1]['query']['bool']['should'];
        $subjects = $should[0]['more_like_this']['like'][0]['doc']['tags_flat']['t'];
        self::assertCount(50, $subjects);
        self::assertSame(' Exact subject ', $subjects[0]);
        self::assertCount(10, $should[1]['constant_score']['filter']['terms']['tags_flat.N']);
    }

    public function testPlaceholderAuthorsWithoutSubjectsDoNotRecommendUnrelatedBooks(): void
    {
        $controller = $this->controller([[$this->event('a', [['N', ' Anonymous '], ['N', 'UNKNOWN']])]]);
        self::assertSame('[]', $controller->recommendations($this->request())->getContent());
        self::assertCount(1, $this->queries);
    }

    public function testSubjectsStillRecommendWhenAuthorsArePlaceholders(): void
    {
        $controller = $this->controller([[$this->event('a', [['t', 'Science fiction'], ['N', 'anonymous'], ['N', 'Unknown']])], []]);
        self::assertSame(200, $controller->recommendations($this->request())->getStatusCode());
        $should = $this->queries[1]['query']['bool']['should'];
        self::assertCount(1, $should);
        self::assertArrayHasKey('more_like_this', $should[0]);
    }

    public function testPlaceholderAuthorsDoNotConsumeAuthorLimit(): void
    {
        $tags = [['N', 'anonymous'], ['N', 'unknown']];
        for ($i = 0; $i < 12; ++$i) {
            $tags[] = ['N', ' Author '.$i.' '];
        }
        $controller = $this->controller([[$this->event('a', $tags)], []]);
        self::assertSame(200, $controller->recommendations($this->request())->getStatusCode());
        $authors = $this->queries[1]['query']['bool']['should'][0]['constant_score']['filter']['terms']['tags_flat.N'];
        self::assertCount(10, $authors);
        self::assertSame(' Author 0 ', $authors[0]);
        self::assertSame(' Author 9 ', $authors[9]);
    }

    public function testAuthorOnlySeedUsesAuthorMatching(): void
    {
        $controller = $this->controller([[$this->event('a', [['N', 'Author']])], []]);
        self::assertSame('[]', $controller->recommendations($this->request())->getContent());
        self::assertArrayHasKey('constant_score', $this->queries[1]['query']['bool']['should'][0]);
    }

    public function testSeedWithoutSignalsReturnsEmptyWithoutCandidateSearch(): void
    {
        $controller = $this->controller([[$this->event('a', [['T', 'Title'], ['t', ' ']])]]);
        $response = $controller->recommendations($this->request());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('[]', $response->getContent());
        self::assertCount(1, $this->queries);
    }

    public function testMissingSeedReturns404(): void
    {
        self::assertSame(404, $this->controller([[]])->recommendations($this->request())->getStatusCode());
    }

    public function testMalformedSeedReturns404(): void
    {
        self::assertSame(404, $this->controller([[['id' => str_repeat('a', 64)]]])->recommendations($this->request())->getStatusCode());
    }

    public function testSectionSeedReturns400(): void
    {
        self::assertSame(400, $this->controller([[$this->event('a', [], 30041)]])->recommendations($this->request())->getStatusCode());
    }

    public function testInvalidRequestDoesNotSearch(): void
    {
        $response = $this->controller([])->recommendations($this->request(['language' => 'en']));
        self::assertSame(400, $response->getStatusCode());
        self::assertArrayHasKey('details', json_decode($response->getContent(), true));
    }

    public function testElasticsearchFailureReturns503WithoutConnectionDetails(): void
    {
        $response = $this->controller([new \RuntimeException('secret host')])->recommendations($this->request());
        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('secret host', $response->getContent());
    }

    private function controller(array $responses): PublicationController
    {
        $client = $this->createMock(Client::class);
        $index = $this->createMock(Index::class);
        $client->method('getIndex')->with('books-test')->willReturn($index);
        $index->expects(self::exactly(count($responses)))->method('search')->willReturnCallback(function (array $query) use (&$responses): ResultSet {
            $this->queries[] = $query;
            $response = array_shift($responses);
            if ($response instanceof \Throwable) {
                throw $response;
            }
            $results = array_map(static fn (array $source): Result => new Result(['_id' => 'different-es-id', '_source' => $source]), $response);
            $resultSet = $this->createMock(ResultSet::class);
            $resultSet->method('getResults')->willReturn($results);

            return $resultSet;
        });
        $books = new BooksIndex($client, new NullLogger(), 'books-test');
        $presenter = new NostrEventPresenter(new NullLogger());
        $service = new PublicationRecommendationService($books, new EventQueryBuilder(), new RecommendationQueryBuilder(), $presenter);

        return new PublicationController(new RequestDecoder(), new PublicationQueryBuilder(), new SectionQueryBuilder(), $books, $presenter, $service);
    }

    private function request(array $overrides = []): Request
    {
        return Request::create('/books/api/publications/recommendations', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(array_replace(['seed_event_id' => str_repeat('a', 64)], $overrides), JSON_THROW_ON_ERROR));
    }

    private function event(string $id, array $tags, int $kind = 30040): array
    {
        return [
            'content' => '',
            'created_at' => 1700000000,
            'id' => str_repeat($id, 64),
            'kind' => $kind,
            'pubkey' => str_repeat('9', 64),
            'sig' => str_repeat('8', 128),
            'tags' => $tags,
        ];
    }
}
