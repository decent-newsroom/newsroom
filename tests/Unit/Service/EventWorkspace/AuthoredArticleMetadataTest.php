<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\EventWorkspace;

use App\Entity\Article;
use App\Entity\Event;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\AuthoredArticleMetadata;
use App\Service\EventWorkspace\AuthoredArticlePreview;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\ContentAuthorAccessPolicy;
use PHPUnit\Framework\TestCase;

final class AuthoredArticleMetadataTest extends TestCase
{
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const MEMBER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const ID = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    public function testOriginalTagsBecomeEscapableFacetsWithoutHtmlOrTargetLookups(): void
    {
        $article = $this->article([
            ['t', 'nostr'], ['t', 'nostr'], ['t', 'čaj'], ['t', '<script>topic</script>'],
            ['p', self::MEMBER, 'wss://untrusted.example'], ['p', strtoupper(self::MEMBER)],
            ['p', 'invalid'], ['r', 'https://example.com/source'], ['r', 'https://example.com/source'],
            ['r', 'javascript:alert(1)'], ['r', '//example.com/relative'], ['r', 'https://'],
            ['lang', 'sl'], ['client', '<script>client</script>'],
            ['t', ''], ['t', ['not text']], ['t', 123], 'malformed',
        ]);
        $article->setTopics(['not-from-original']);
        $article->setProcessedHtml('<h1>Do not render this</h1>');
        $raw = $article->getRaw();
        $facets = $this->metadata([$article])[self::ID];

        self::assertFalse($facets['sensitive']);
        self::assertSame(['nostr', 'čaj', '<script>topic</script>'], $facets['hashtags']);
        self::assertSame([(new NostrNip19Service())->encodeNpub(self::MEMBER)], $facets['mentions']);
        self::assertSame(['https://example.com/source'], $facets['sources']);
        self::assertSame('sl', $facets['language']);
        self::assertSame('<script>client</script>', $facets['client']);
        self::assertSame($raw, $article->getRaw());
        self::assertArrayNotHasKey('content', $facets);
    }

    public function testExactLocalEventTakesPrecedenceOverArticleRawForDrafts(): void
    {
        $article = $this->article([['t', 'projection-raw']]);
        $article->setKind(30024);
        $event = new Event();
        $event->setId(self::ID);
        $event->setKind(30024);
        $event->setPubkey(self::OWNER);
        $event->setCreatedAt(123);
        $event->setTags([['d', 'article'], ['t', 'original-draft']]);
        $event->setContent('Signed draft');

        self::assertSame(['original-draft'], $this->metadata([$article], [$event])[self::ID]['hashtags']);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function mismatchedOriginals(): iterable
    {
        yield 'different revision' => ['id', str_repeat('d', 64)];
        yield 'different author' => ['pubkey', self::MEMBER];
        yield 'different kind' => ['kind', 30024];
        yield 'different timestamp' => ['created_at', 124];
        yield 'different coordinate' => ['tags', [['d', 'different'], ['t', 'secret']]];
        yield 'missing original tags' => ['tags', null];
        yield 'missing original content' => ['content', null];
    }

    /** @dataProvider mismatchedOriginals */
    public function testMismatchedOriginalDoesNotExposeTags(string $field, mixed $value): void
    {
        $article = $this->article([['t', 'secret']]);
        $raw = $article->getRaw();
        $raw[$field] = $value;
        $article->setRaw($raw);

        self::assertSame([], $this->metadata([$article]));
    }

    /** @return iterable<string, array{list<array>}> */
    public static function warningTags(): iterable
    {
        yield 'content warning' => [[['content-warning', 'private reason']]];
        yield 'NSFW hashtag' => [[['t', 'NSFW']]];
        yield 'qualified label' => [[['l', 'adult', 'nsfw']]];
    }

    /** @dataProvider warningTags */
    public function testSensitiveTagMetadataIsWithheld(array $warnings): void
    {
        $article = $this->article(array_merge([
            ['t', 'private-topic'], ['p', self::MEMBER], ['r', 'https://example.com/private'],
            ['lang', 'sl'], ['client', 'Private client'],
        ], $warnings));
        self::assertSame([
            'sensitive' => true, 'hashtags' => [], 'mentions' => [], 'sources' => [],
            'language' => null, 'client' => null,
        ], $this->metadata([$article])[self::ID]);
    }

    /** @return iterable<string, array{bool, bool, bool}> */
    public static function restrictedSources(): iterable
    {
        yield 'suppressed author' => [true, false, false];
        yield 'hidden coordinate' => [false, true, false];
        yield 'deleted event' => [false, false, true];
    }

    /** @dataProvider restrictedSources */
    public function testRestrictedSourcesDoNotExposeTags(bool $suppressed, bool $hidden, bool $deleted): void
    {
        self::assertSame([], $this->metadata(
            [$this->article([['t', 'secret']])], [], $suppressed, $hidden, $deleted,
        ));
    }

    public function testMissingOriginalAndForeignOwnerAreNotReconstructedFromProjections(): void
    {
        $article = $this->article([['t', 'secret']]);
        $article->setRaw(null);
        $article->setTopics(['projection-only']);
        self::assertSame([], $this->metadata([$article]));

        $article->setPubkey(self::MEMBER);
        self::assertSame([], $this->metadata([$article]));
    }

    private function article(array $tags): Article
    {
        $article = (new Article())->setEventId(self::ID)->setPubkey(self::OWNER)
            ->setKind(30023)->setSlug('article')->setCreatedAt(new \DateTimeImmutable('@123'));
        $article->setRaw([
            'id' => self::ID, 'pubkey' => self::OWNER, 'kind' => 30023, 'created_at' => 123,
            'tags' => array_merge([['d', 'article']], $tags), 'content' => 'Original body',
        ]);

        return $article;
    }

    /** @param list<Article> $articles @param list<Event> $originals */
    private function metadata(
        array $articles,
        array $originals = [],
        bool $suppressed = false,
        bool $hidden = false,
        bool $deleted = false,
    ): array {
        $events = $this->createMock(EventRepository::class);
        $events->expects($suppressed || $articles[0]->getPubkey() !== self::OWNER ? self::never() : self::once())
            ->method('findBy')
            ->with(['id' => [self::ID], 'pubkey' => self::OWNER, 'kind' => [30023, 30024]])
            ->willReturn($originals);
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $authors->method('isSuppressed')->willReturn($suppressed);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->method('isSuppressed')->willReturn($deleted);
        $coordinates = $this->createMock(HiddenCoordinateRepository::class);
        $coordinates->method('findHiddenCoordinates')->willReturn($hidden ? ['30023:' . self::OWNER . ':article'] : []);
        $nip19 = new NostrNip19Service();

        return (new AuthoredArticleMetadata(
            new AuthoredArticlePreview(new EventPresentationFactory(new RecordIdentityService(), $nip19)),
            $events, $authors, $deletions, $coordinates, $nip19,
        ))->forArticles($articles, self::OWNER);
    }
}
