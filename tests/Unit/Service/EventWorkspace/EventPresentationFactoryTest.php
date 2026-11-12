<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\EventWorkspace;

use App\Dto\EventWorkspace\EventBodyFormat;
use App\Dto\EventWorkspace\EventPresentation;
use App\Dto\EventWorkspace\EventReferenceRole;
use App\Dto\EventWorkspace\EventReferenceStatus;
use App\Dto\EventWorkspace\EventReferenceType;
use App\Entity\Event;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use App\Util\Nip10TagParser;
use PHPUnit\Framework\TestCase;

final class EventPresentationFactoryTest extends TestCase
{
    private const SOURCE_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const AUTHOR = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const TARGET_ID = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const VERSION_ID = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';
    private const PROFILE = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    private EventPresentationFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service());
    }

    public function testRawArticleNeedsNeitherProjectionNorRenderedHtml(): void
    {
        $tags = [
            ['d', 'Article:With:Colons'],
            ['title', '  A raw article  '],
            ['summary', ' A useful summary '],
            ['published_at', '1700000000'],
            ['image', 'https://example.test/cover.png'],
            ['content-warning', ' spoilers '],
            ['e', self::TARGET_ID, 'wss://relay.example'],
        ];
        $body = "# Markdown title\n\nUnconverted body.";
        $event = $this->event(30023, $tags, $body, strtoupper(self::AUTHOR));
        $event->setDTag('stale-projection');

        $presentation = $this->factory->fromAuthorizedEvent($event);

        self::assertSame(self::SOURCE_ID, $presentation->eventId);
        self::assertSame(30023, $presentation->kind);
        self::assertSame(strtoupper(self::AUTHOR), $presentation->authorPubkey);
        self::assertStringStartsWith('npub1', $presentation->authorNpub);
        self::assertSame('30023:' . self::AUTHOR . ':Article:With:Colons', $presentation->coordinate);
        self::assertSame('coord:' . $presentation->coordinate, $presentation->recordUid);
        self::assertSame(1710000000, $presentation->createdAt);
        self::assertSame(1700000000, $presentation->publishedAt);
        self::assertSame('A raw article', $presentation->title);
        self::assertNull($presentation->description);
        self::assertSame('A useful summary', $presentation->descriptionOnReveal);
        self::assertSame('https://example.test/cover.png', $presentation->imageUrl);
        self::assertTrue($presentation->hasContentWarning);
        self::assertSame('spoilers', $presentation->contentWarningReason);
        self::assertTrue($presentation->isSensitive);
        self::assertSame(EventBodyFormat::Markdown, $presentation->bodyFormat);
        self::assertSame($body, $presentation->textContent);
        self::assertSame($tags, $presentation->getRawTags());
        self::assertCount(1, $presentation->references);
        self::assertSame(EventReferenceType::EventId, $presentation->references[0]->type);
        self::assertSame(EventReferenceStatus::Unresolved, $presentation->references[0]->status);
        self::assertSame(6, $presentation->references[0]->position);

        $event->setTags([]);
        $event->setContent('changed later');
        self::assertSame($tags, $presentation->getRawTags());
        self::assertSame($body, $presentation->textContent);
    }

    public function testAuthorizedRawFieldsAndEntityProduceTheSameSnapshot(): void
    {
        $tags = [['d', 'slug'], ['title', 'Title'], ['p', self::PROFILE]];
        $entity = $this->event(30023, $tags, 'body');

        self::assertEquals(
            $this->factory->fromAuthorizedEvent($entity),
            $this->factory->fromAuthorizedFields(self::SOURCE_ID, 30023, self::AUTHOR, 1710000000, $tags, 'body'),
        );
    }

    public function testUnknownKindUsesAltEvenWithEmptyContent(): void
    {
        $view = $this->present(60000, [['alt', '  Unknown event description  ']], '');

        self::assertNull($view->title);
        self::assertSame(60000, $view->kind);
        self::assertSame(self::SOURCE_ID, $view->eventId);
        self::assertSame('Unknown event description', $view->description);
        self::assertSame(EventBodyFormat::Opaque, $view->bodyFormat);
        self::assertNull($view->textContent);
        self::assertSame('event:' . self::SOURCE_ID, $view->recordUid);
    }

    public function testZapReceiptDoesNotExposeTheEmbeddedZapRequest(): void
    {
        $request = '{"kind":9734,"content":"warned secret","tags":[["content-warning","spoilers"]],"id":"signed-id","sig":"signed-signature"}';
        $tags = [
            ['description', $request],
            ['e', self::TARGET_ID],
            ['description', 'not even valid JSON'],
        ];
        $view = $this->present(9735, $tags, 'opaque receipt payload');

        self::assertSame($tags, $view->getRawTags());
        self::assertSame(EventBodyFormat::Opaque, $view->bodyFormat);
        self::assertNull($view->textContent);
        self::assertNull($view->description);
        self::assertNull($view->descriptionOnReveal);
        self::assertFalse($view->isSensitive);
        self::assertSame(self::TARGET_ID, $view->references[0]->target);
    }

    public function testZapReceiptUsesHumanReadableSummaryOrAltInsteadOfDescription(): void
    {
        $request = '{"kind":9734,"content":"warned secret","tags":[["content-warning"]]}';
        $withSummary = $this->present(9735, [
            ['description', $request],
            ['alt', 'Alternate'],
            ['summary', ' Summary '],
        ], 'not a text excerpt');
        $withAlt = $this->present(9735, [['description', $request], ['alt', '0']]);
        $withWarning = $this->present(9735, [
            ['description', $request],
            ['alt', 'Safe alternate'],
            ['content-warning'],
        ]);

        self::assertSame('Summary', $withSummary->description);
        self::assertSame('0', $withAlt->description);
        self::assertTrue($withWarning->isSensitive);
        self::assertNull($withWarning->description);
        self::assertSame('Safe alternate', $withWarning->descriptionOnReveal);
        self::assertSame('Still a description', $this->present(9734, [['description', 'Still a description']])->description);
    }

    /** @dataProvider opaqueKinds */
    public function testOpaqueOrPotentiallyEncryptedContentIsNotExposedOrExcerpted(int $kind): void
    {
        $view = $this->present($kind, [], 'encrypted-or-opaque-payload');

        self::assertSame(EventBodyFormat::Opaque, $view->bodyFormat);
        self::assertNull($view->textContent);
        self::assertNull($view->description);
    }

    /** @return array<string, array{int}> */
    public static function opaqueKinds(): array
    {
        return [
            'profile JSON' => [0],
            'follow list content' => [3],
            'encrypted direct message' => [4],
            'private message' => [14],
            'bookmark list' => [10003],
            'bookmark set' => [30003],
            'publication index' => [30040],
            'application data' => [30078],
            'unknown kind' => [60000],
        ];
    }

    public function testEmptyDataLeavesLocalizedTitleToTheCaller(): void
    {
        $view = $this->factory->fromAuthorizedFields('', 65000, '', 0, [], '');

        self::assertNull($view->title);
        self::assertSame(65000, $view->kind);
        self::assertSame('', $view->eventId);
        self::assertSame('', $view->authorPubkey);
        self::assertNull($view->authorNpub);
        self::assertNull($view->coordinate);
        self::assertNull($view->recordUid);
        self::assertNull($view->description);
        self::assertSame([], $view->getRawTags());
        self::assertSame([], $view->references);

        $malformedIdentity = $this->factory->fromAuthorizedFields('not-an-id', 65000, '', 0, [], '');
        self::assertNull($malformedIdentity->title);
        self::assertSame('not-an-id', $malformedIdentity->eventId);

        $emptyTitles = $this->present(60000, [['title', '  '], ['name', ''], ['d', ' ']]);
        self::assertNull($emptyTitles->title);
        self::assertSame(60000, $emptyTitles->kind);
        self::assertSame(self::SOURCE_ID, $emptyTitles->eventId);
    }

    public function testInvalidAuthorFallsBackToTheOriginalPubkeyWithoutEncoding(): void
    {
        $view = $this->present(30023, [['d', 'slug']], '', 'not-a-pubkey');

        self::assertSame('not-a-pubkey', $view->authorPubkey);
        self::assertNull($view->authorNpub);
        self::assertNull($view->coordinate);
        self::assertNull($view->recordUid);
    }

    /**
     * The encoder uses a static Bech32 dependency, so isolate its failure double
     * from the other tests which exercise the real encoder.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testUnexpectedNpubEncoderFailuresPropagate(): void
    {
        self::assertFalse(class_exists(\nostriphant\NIP19\Bech32::class, false));
        self::assertTrue(class_alias(ThrowingNpubEncoder::class, \nostriphant\NIP19\Bech32::class, false));
        self::assertNull($this->present(1, [], '', 'not-a-pubkey')->authorNpub);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected npub encoding failure');
        $this->present(1);
    }

    public function testMetadataPrecedenceAndEmptyValues(): void
    {
        $view = $this->present(30023, [
            ['title', ' '],
            ['name', ' Name '],
            ['d', 'identifier'],
            ['description', ' Description '],
            ['alt', 'Alt'],
            ['published_at', '-1'],
            ['published_at', '1700000001'],
            ['content-warning'],
        ], 'Body');

        self::assertSame('Name', $view->title);
        self::assertNull($view->description);
        self::assertSame('Description', $view->descriptionOnReveal);
        self::assertSame(1700000001, $view->publishedAt);
        self::assertTrue($view->hasContentWarning);
        self::assertNull($view->contentWarningReason);
        self::assertTrue($view->isSensitive);

        $withTitleAndSummary = $this->present(30023, [
            ['name', 'Name before title'],
            ['title', 'Title wins'],
            ['description', 'Description before summary'],
            ['summary', 'Summary wins'],
        ]);
        self::assertSame('Title wins', $withTitleAndSummary->title);
        self::assertSame('Summary wins', $withTitleAndSummary->description);

        self::assertSame('identifier', $this->present(30023, [['d', 'identifier']])->title);
        self::assertSame('Alt', $this->present(30023, [['alt', 'Alt']], 'Body')->description);
    }

    public function testZeroStringsAndEmptyIdentifiersAreNotTreatedAsMissing(): void
    {
        $view = $this->present(30023, [
            ['d', '0'],
            ['title', '0'],
            ['summary', '0'],
            ['published_at', '0'],
        ], 'fallback');

        self::assertSame('0', $view->title);
        self::assertSame('0', $view->description);
        self::assertSame(0, $view->publishedAt);
        self::assertSame('30023:' . self::AUTHOR . ':0', $view->coordinate);
        self::assertSame('0', $this->present(30023, [['d', '0']])->title);
        self::assertSame('0', $this->present(30023, [['name', '0']])->title);
        self::assertSame('0', $this->present(30023, [['title', '0']])->title);

        $empty = $this->present(30023, [['d', '']]);
        $absent = $this->present(30023);
        self::assertNull($empty->title);
        self::assertNull($absent->title);
        self::assertSame('30023:' . self::AUTHOR . ':', $empty->coordinate);
        self::assertSame($empty->coordinate, $absent->coordinate);
        self::assertSame([['d', '']], $empty->getRawTags());
    }

    /**
     * @dataProvider sensitiveTags
     * @param array<array-key, mixed> $tags
     */
    public function testSensitiveDescriptionsRequireExplicitReveal(array $tags, bool $entityNsfw): void
    {
        $view = $this->present(1, $tags, 'Sensitive body text');

        self::assertTrue($view->isSensitive);
        self::assertNull($view->description);
        self::assertSame('Sensitive body text', $view->descriptionOnReveal);
        self::assertSame('Sensitive body text', $view->textContent);
        self::assertSame($tags, $view->getRawTags());
        self::assertSame($entityNsfw, $this->event(1, $tags, 'Sensitive body text')->isNSFW());
    }

    /** @return array<string, array{array<array-key, mixed>, bool}> */
    public static function sensitiveTags(): array
    {
        return [
            'warning without reason' => [[['content-warning']], true],
            'warning with empty reason' => [[['content-warning', '']], true],
            'NIP-32 nsfw namespace' => [[['L', 'NSFW']], true],
            'nsfw hashtag' => [[['t', 'NsFw']], true],
            'adult hashtag' => [[['t', 'adult']], true],
            'explicit hashtag' => [[['t', 'explicit']], true],
            'age hashtag' => [[['t', '18+']], true],
            'nsfl hashtag' => [[['t', 'nsfl']], true],
            'NIP-36 namespace' => [[['L', 'content-warning']], false],
            'NIP-36 qualified label' => [[['l', 'nudity', 'content-warning']], false],
            'NIP-32 self-label without L tag' => [[['l', 'nudity', 'nsfw']], false],
            'NIP-32 self-label mixed-case namespace' => [[['l', 'nudity', 'NsFw']], false],
        ];
    }

    public function testSensitivityChecksTolerateMalformedTagsAndKeepSafeDescriptions(): void
    {
        $view = $this->present(1, [
            ['t', []],
            ['L', 5],
            ['l', 'ordinary', 'other-namespace'],
            ['l', 'nsfw', 'other-namespace'],
            ['l', 'nudity', []],
            ['summary', 'Safe summary'],
        ], 'Safe body');

        self::assertFalse($view->isSensitive);
        self::assertFalse($view->hasContentWarning);
        self::assertSame('Safe summary', $view->description);
        self::assertNull($view->descriptionOnReveal);
    }

    /**
     * @dataProvider directSensitiveEvidence
     * @param array<array-key, mixed> $tags
     */
    public function testDirectPresentationCannotIgnoreSensitiveEvidence(
        array $tags,
        bool $warningFlag,
        ?string $reason,
        bool $sensitiveFlag,
        bool $expectedWarning,
        ?string $expectedReason,
    ): void {
        $view = $this->directPresentation($tags, $warningFlag, $reason, $sensitiveFlag);

        self::assertTrue($view->isSensitive);
        self::assertSame($expectedWarning, $view->hasContentWarning);
        self::assertSame($expectedReason, $view->contentWarningReason);
        self::assertNull($view->description);
        self::assertSame('Hidden description', $view->descriptionOnReveal);
        self::assertSame('Original body', $view->textContent);
        self::assertSame($tags, $view->getRawTags());
    }

    /** @return array<string, array{array<array-key, mixed>, bool, ?string, bool, bool, ?string}> */
    public static function directSensitiveEvidence(): array
    {
        return [
            'warning flag despite false sensitivity' => [[], true, null, false, true, null],
            'sensitive flag without a warning tag' => [[], false, null, true, false, null],
            'reason without flags' => [[], false, 'spoilers', false, true, 'spoilers'],
            'literal zero reason' => [[], false, '0', false, true, '0'],
            'empty explicit reason' => [[], false, '', false, true, ''],
            'warning without value' => [[['content-warning']], false, null, false, true, null],
            'warning with empty value' => [[['content-warning', '']], false, null, false, true, null],
            'warning with value' => [[['content-warning', ' spoilers ']], false, null, false, true, 'spoilers'],
            'warning with zero value' => [[['content-warning', '0']], false, null, false, true, '0'],
            'malformed warning value' => [[['content-warning', []]], false, null, false, true, null],
            'first warning remains authoritative' => [
                [['content-warning'], ['content-warning', 'later']], false, null, false, true, null,
            ],
            'explicit reason retained with warning tag' => [
                [['content-warning', 'tag reason']], false, 'caller reason', false, true, 'caller reason',
            ],
            'NSFW hashtag' => [[['t', 'NsFw']], false, null, false, false, null],
            'NSFW namespace' => [[['L', 'NSFW']], false, null, false, false, null],
            'warning namespace' => [[['L', 'content-warning']], false, null, false, false, null],
            'qualified warning label' => [[['l', 'nudity', 'content-warning']], false, null, false, false, null],
            'qualified NSFW label without L tag' => [[['l', 'nudity', 'nsfw']], false, null, false, false, null],
            'qualified NSFW label with mixed-case namespace' => [[['l', 'nudity', 'NsFw']], false, null, false, false, null],
        ];
    }

    public function testDirectPresentationKeepsOrdinaryDescriptionsAndExplicitRevealText(): void
    {
        $safe = $this->directPresentation([['t', 'safe']]);
        self::assertFalse($safe->hasContentWarning);
        self::assertFalse($safe->isSensitive);
        self::assertSame('Hidden description', $safe->description);
        self::assertNull($safe->descriptionOnReveal);

        $sensitive = $this->directPresentation([], false, 'reason', false, 'Hidden description', 'Explicit reveal');
        self::assertNull($sensitive->description);
        self::assertSame('Explicit reveal', $sensitive->descriptionOnReveal);
    }

    public function testTextExcerptIsBoundedAndBodyIsNotConverted(): void
    {
        $body = "First line\n" . str_repeat('ž', 250);
        $view = $this->present(30023, [], $body);

        self::assertSame($body, $view->textContent);
        self::assertStringStartsWith('First line ', $view->description);
        self::assertStringEndsWith('…', $view->description);
        self::assertSame(220, mb_strlen($view->description, 'UTF-8'));

        // This is untrusted text, not pre-rendered or pre-sanitized HTML.
        $markup = 'Literal <script>alert("x")</script> text';
        $rawText = $this->present(1, [], $markup);
        self::assertSame($markup, $rawText->description);
        self::assertSame($markup, $rawText->textContent);
    }

    /** @dataProvider splitUtf8CodePoints */
    public function testExcerptOmitsACodePointSplitByTheScanLimit(string $codePoint, int $bytesBeforeLimit): void
    {
        $start = 'boundary ';
        $body = $start . str_repeat(' ', 4096 - strlen($start) - $bytesBeforeLimit) . $codePoint . ' after';
        $view = $this->present(30023, [], $body);

        self::assertFalse(mb_check_encoding(substr($body, 0, 4096), 'UTF-8'));
        self::assertSame('boundary', $view->description);
        self::assertSame($body, $view->textContent);
    }

    /** @return array<string, array{string, int}> */
    public static function splitUtf8CodePoints(): array
    {
        return [
            'two-byte code point, one byte inside' => ['é', 1],
            'three-byte code point, one byte inside' => ['€', 1],
            'three-byte code point, two bytes inside' => ['€', 2],
            'four-byte code point, one byte inside' => ['💡', 1],
            'four-byte code point, two bytes inside' => ['💡', 2],
            'four-byte code point, three bytes inside' => ['💡', 3],
        ];
    }

    public function testExcerptKeepsACompleteCodePointAtTheScanLimit(): void
    {
        $start = 'boundary ';
        $body = $start . str_repeat(' ', 4096 - strlen($start) - strlen('€')) . '€ after';
        $view = $this->present(30023, [], $body);

        self::assertSame('boundary €', $view->description);
        self::assertSame($body, $view->textContent);
    }

    public function testInvalidSequenceAtTheScanLimitDoesNotHideMetadata(): void
    {
        $start = 'boundary ';
        $body = $start . str_repeat(' ', 4096 - strlen($start) - 1) . "\xe2x";
        $view = $this->present(30023, [['title', 'Still available'], ['e', self::TARGET_ID]], $body);

        self::assertNull($view->description);
        self::assertSame('Still available', $view->title);
        self::assertSame($body, $view->textContent);
        self::assertSame(self::TARGET_ID, $view->references[0]->target);
    }

    public function testInvalidEarlierBytesAreNotHiddenByASplitCodePoint(): void
    {
        $start = "boundary \xff";
        $body = $start . str_repeat(' ', 4096 - strlen($start) - 2) . '€';
        $view = $this->present(30023, [['title', 'Still available']], $body);

        self::assertNull($view->description);
        self::assertSame('Still available', $view->title);
        self::assertSame($body, $view->textContent);
    }

    public function testInvalidDataBeyondAnAlreadyCompleteScanIsNotProcessed(): void
    {
        $start = 'boundary ';
        $body = $start . str_repeat(' ', 4096 - strlen($start)) . "\xff\xfe";
        $view = $this->present(30023, [], $body);

        self::assertSame('boundary', $view->description);
        self::assertSame($body, $view->textContent);
    }

    public function testBadBodyTextCannotDiscardMetadataOrReferences(): void
    {
        $body = "\xff\xfe";
        $view = $this->present(30023, [
            ['title', 'Still available'],
            ['e', self::TARGET_ID],
        ], $body);

        self::assertSame('Still available', $view->title);
        self::assertSame(self::SOURCE_ID, $view->eventId);
        self::assertSame($body, $view->textContent);
        self::assertNull($view->description);
        self::assertCount(1, $view->references);
        self::assertSame(self::TARGET_ID, $view->references[0]->target);
    }

    public function testMalformedAndPartialTagsRemainInTheRawSnapshotAndReferences(): void
    {
        $tags = [
            null,
            'not a tag',
            [],
            ['d'],
            ['a'],
            ['e', 42],
            ['p', null],
            ['a', 'bad-coordinate'],
            ['e', self::TARGET_ID],
            ['p', self::PROFILE],
            ['a', '30023:' . strtoupper(self::AUTHOR) . ':Some:Identifier'],
            ['summary', ['not text']],
        ];
        $view = $this->present(30040, $tags);

        self::assertSame($tags, $view->getRawTags());
        self::assertNull($view->coordinate); // The first d tag is malformed.
        self::assertCount(7, $view->references);
        self::assertSame([4, 5, 6, 7, 8, 9, 10], array_map(static fn ($ref) => $ref->position, $view->references));
        foreach (array_slice($view->references, 0, 4) as $reference) {
            self::assertSame(EventReferenceStatus::Invalid, $reference->status);
            self::assertNull($reference->target);
        }
        self::assertSame(EventReferenceStatus::Unresolved, $view->references[4]->status);
        self::assertSame('30023:' . self::AUTHOR . ':Some:Identifier', $view->references[6]->target);
        self::assertSame('Some:Identifier', $view->references[6]->targetIdentifier);
    }

    public function testInvalidReferencePreservesItsRawBytesAndPosition(): void
    {
        $rawValue = "not-an-id:\x00\xff";
        $tags = [['title', 'Source'], ['e', $rawValue], ['p', '0']];
        $view = $this->present(60000, $tags);

        self::assertSame($tags, $view->getRawTags());
        self::assertSame(1, $view->references[0]->position);
        self::assertSame($rawValue, $view->references[0]->rawValue);
        self::assertSame(EventReferenceStatus::Invalid, $view->references[0]->status);
        self::assertSame('0', $view->references[1]->rawValue);
        self::assertSame(EventReferenceStatus::Invalid, $view->references[1]->status);
    }

    public function testDuplicatesAndOriginalTagPositionsAreNotCollapsed(): void
    {
        $tags = [
            ['title', 'Directory'],
            ['e', self::TARGET_ID],
            ['t', 'topic'],
            ['e', self::TARGET_ID],
            ['a', '30023:' . self::AUTHOR . ':same'],
            ['a', '30023:' . self::AUTHOR . ':same'],
        ];
        $view = $this->present(30045, $tags);

        self::assertSame($tags, $view->getRawTags());
        self::assertSame([1, 3, 4, 5], array_map(static fn ($ref) => $ref->position, $view->references));
        self::assertSame(self::TARGET_ID, $view->references[0]->target);
        self::assertSame($view->references[0]->target, $view->references[1]->target);
        self::assertSame($view->references[2]->target, $view->references[3]->target);
        foreach ($view->references as $reference) {
            self::assertSame(self::SOURCE_ID, $reference->sourceEventId);
            self::assertSame(EventReferenceRole::Content, $reference->role);
            self::assertSame(EventReferenceStatus::Unresolved, $reference->status);
        }
    }

    public function testReferenceTypesAndCoordinateIdentityAreDistinct(): void
    {
        $upperAuthor = strtoupper(self::AUTHOR);
        $view = $this->present(30045, [
            ['a', "30023:{$upperAuthor}:Part:One", 'wss://relay.example'],
            ['e', strtoupper(self::TARGET_ID)],
            ['p', strtoupper(self::PROFILE)],
            ['a', "3:{$upperAuthor}:"],
            ['a', "0:{$upperAuthor}:"],
            ['a', "30023:{$upperAuthor}:"],
        ]);
        [$address, $exact, $profile, $followList, $metadata, $emptyIdentifier] = $view->references;

        self::assertSame(EventReferenceType::Address, $address->type);
        self::assertSame("30023:{$upperAuthor}:Part:One", $address->rawValue);
        self::assertSame('30023:' . self::AUTHOR . ':Part:One', $address->target);
        self::assertSame(30023, $address->targetKind);
        self::assertSame(self::AUTHOR, $address->targetPubkey);
        self::assertSame('Part:One', $address->targetIdentifier);
        self::assertSame('wss://relay.example', $address->relayHint);
        self::assertSame(EventReferenceType::EventId, $exact->type);
        self::assertSame(self::TARGET_ID, $exact->target);
        self::assertSame(EventReferenceType::Profile, $profile->type);
        self::assertSame(self::PROFILE, $profile->target);
        self::assertSame('3:' . self::AUTHOR, $followList->target);
        self::assertSame('0:' . self::AUTHOR, $metadata->target);
        self::assertSame('', $metadata->targetIdentifier);
        self::assertSame('30023:' . self::AUTHOR . ':', $emptyIdentifier->target);
    }

    /** @dataProvider malformedAddresses */
    public function testInvalidAddressesStayVisible(string $address): void
    {
        $reference = $this->present(30040, [['a', $address]])->references[0];

        self::assertSame($address, $reference->rawValue);
        self::assertSame(EventReferenceType::Address, $reference->type);
        self::assertSame(EventReferenceStatus::Invalid, $reference->status);
        self::assertNull($reference->target);
    }

    /** @return array<string, array{string}> */
    public static function malformedAddresses(): array
    {
        return [
            'missing trailing separator' => ['30023:' . self::AUTHOR],
            'invalid pubkey' => ['30023:bad:slug'],
            'non-numeric kind' => ['30023oops:' . self::AUTHOR . ':slug'],
            'ordinary event kind' => ['1:' . self::AUTHOR . ':'],
            'non-empty replaceable identifier' => ['3:' . self::AUTHOR . ':wrong'],
        ];
    }

    /** @dataProvider versionedSourceKinds */
    public function testIndexAndDirectoryVersionPinsHaveTheirSpecifiedShape(int $kind): void
    {
        $coordinate = '30023:' . self::AUTHOR . ':article';
        $view = $this->present($kind, [
            ['a', $coordinate, 'wss://relay.example', strtoupper(self::VERSION_ID)],
            ['a', $coordinate, '', ''],
        ]);

        self::assertSame(EventReferenceRole::Content, $view->references[0]->role);
        self::assertSame(EventReferenceStatus::Unresolved, $view->references[0]->status);
        self::assertSame('wss://relay.example', $view->references[0]->relayHint);
        self::assertSame(self::VERSION_ID, $view->references[0]->versionEventId);
        self::assertNull($view->references[1]->relayHint);
        self::assertNull($view->references[1]->versionEventId);
        self::assertSame(EventReferenceStatus::Unresolved, $view->references[1]->status);
    }

    /** @return array<string, array{int}> */
    public static function versionedSourceKinds(): array
    {
        return ['publication index' => [30040], 'directory' => [30045]];
    }

    public function testAmbiguousVersionFieldIsRetainedWithoutAssumingLatest(): void
    {
        $tags = [
            ['a', '30023:' . self::AUTHOR . ':article', 'wss://relay.example', 'opaque-marker'],
            ['a', '30023:' . self::AUTHOR . ':article', self::VERSION_ID, 'wss://relay.example'],
            ['a', '30023:' . self::AUTHOR . ':article', '', null],
        ];
        $view = $this->present(30040, $tags);

        self::assertSame($tags, $view->getRawTags());
        self::assertSame(EventReferenceStatus::AmbiguousVersion, $view->references[0]->status);
        self::assertNull($view->references[0]->versionEventId);
        self::assertSame(EventReferenceStatus::AmbiguousVersion, $view->references[1]->status);
        self::assertSame(self::VERSION_ID, $view->references[1]->relayHint);
        self::assertNull($view->references[1]->versionEventId);
        self::assertSame(EventReferenceStatus::Invalid, $view->references[2]->status);
    }

    public function testFourthFieldIsNotUniversallyAVersionOrMarker(): void
    {
        $coordinate = '30045:' . self::AUTHOR . ':directory';
        $link = $this->present(30043, [['a', $coordinate, 'wss://relay.example', 'link']])->references[0];
        $target = $this->present(30044, [['a', $coordinate, 'wss://relay.example', 'target']])->references[0];
        $otherAddress = $this->present(30023, [['a', $coordinate, '', self::VERSION_ID]])->references[0];
        $directoryEvent = $this->present(30045, [['e', self::TARGET_ID, '', self::PROFILE]])->references[0];
        $noteRoot = $this->present(1, [['e', self::TARGET_ID, '', 'root', self::PROFILE]])->references[0];

        foreach ([$link, $target, $otherAddress, $directoryEvent, $noteRoot] as $reference) {
            self::assertNull($reference->versionEventId);
            self::assertSame(EventReferenceStatus::Unresolved, $reference->status);
        }
        self::assertSame(EventReferenceRole::Reference, $link->role);
        self::assertSame(EventReferenceRole::Reference, $target->role);
        self::assertSame(EventReferenceRole::Content, $directoryEvent->role);
        self::assertSame(EventReferenceRole::Root, $noteRoot->role);
    }

    public function testNip10MarkerAndRelayLayoutsKeepOrderedReferenceOccurrences(): void
    {
        $tags = [
            ['title', 'Thread'],
            ['e', self::TARGET_ID, 'wss://relay.one', 'root'],
            ['e', self::TARGET_ID, 'ROOT', 'wss://relay.two'],
            ['e', self::TARGET_ID, 'wss://reply.one', 'RePlY'],
            ['e', self::TARGET_ID, 'reply', 'wss://reply.two'],
            ['e', self::TARGET_ID, 'mEnTiOn', 'wss://mention.example'],
            ['e', 'malformed', 'root', 'wss://malformed.example'],
            ['e', null, 'ROOT', 'wss://partial.example'],
            ['e', self::TARGET_ID, 'unknown', 'wss://unsupported-slot.example'],
            ['e', self::TARGET_ID, 'root', 'https://not-a-relay.example'],
            ['e', self::TARGET_ID, 'wss://unmarked.example'],
            ['e', self::TARGET_ID, 'root', 'REPLY'],
            ['e'],
        ];
        $view = $this->present(1, $tags);

        self::assertSame($tags, $view->getRawTags());
        self::assertSame(range(1, 12), array_map(static fn ($ref) => $ref->position, $view->references));
        self::assertSame([
            EventReferenceRole::Root, EventReferenceRole::Root,
            EventReferenceRole::Parent, EventReferenceRole::Parent, EventReferenceRole::Reference,
            EventReferenceRole::Root, EventReferenceRole::Root, EventReferenceRole::Reference,
            EventReferenceRole::Root, EventReferenceRole::Reference, EventReferenceRole::Parent,
            EventReferenceRole::Reference,
        ], array_map(static fn ($ref) => $ref->role, $view->references));
        self::assertSame([
            'wss://relay.one', 'wss://relay.two',
            'wss://reply.one', 'wss://reply.two', 'wss://mention.example',
            'wss://malformed.example', 'wss://partial.example', null, null,
            'wss://unmarked.example', null, null,
        ], array_map(static fn ($ref) => $ref->relayHint, $view->references));
        self::assertSame(EventReferenceStatus::Invalid, $view->references[5]->status);
        self::assertSame(EventReferenceStatus::Invalid, $view->references[6]->status);
        self::assertSame(EventReferenceStatus::Invalid, $view->references[11]->status);
        self::assertSame(EventReferenceStatus::Unresolved, $view->references[0]->status);
        self::assertSame(self::TARGET_ID, $view->references[0]->target);
        self::assertSame($view->references[0]->target, $view->references[1]->target);
        foreach ([$tags[1], $tags[2]] as $index => $tag) {
            $parsed = Nip10TagParser::findRootReference([$tag]);
            self::assertNotNull($parsed);
            self::assertSame($parsed['relays'][0], $view->references[$index]->relayHint);
        }
    }

    public function testNip10ParserKeepsItsExistingConflictingMarkerPrecedence(): void
    {
        $tags = [
            ['e', self::TARGET_ID, 'root', 'reply'],
            ['e', self::PROFILE, 'RePlY', 'RoOt'],
            ['e', self::AUTHOR, 'wss://later.example', 'root'],
        ];

        self::assertSame(
            ['eventId' => self::PROFILE, 'relays' => []],
            Nip10TagParser::findRootReference($tags),
        );
        $view = $this->present(1, $tags);
        self::assertSame(EventReferenceRole::Parent, $view->references[0]->role);
        self::assertSame(EventReferenceRole::Root, $view->references[1]->role);
        self::assertSame($tags, $view->getRawTags());
    }

    public function testNip10RetainsMixedCaseWebSocketSchemesInBothMarkerPositions(): void
    {
        $tags = [
            ['e', self::TARGET_ID, 'WSS://Relay.One/Path', 'rOoT'],
            ['e', self::TARGET_ID, 'ROOT', 'WsS://Relay.Two'],
            ['e', self::TARGET_ID, 'WS://Relay.Three', 'RePlY'],
            ['e', 'malformed', 'reply', 'wS://Relay.Four'],
        ];
        $view = $this->present(1, $tags);

        self::assertSame($tags, $view->getRawTags());
        self::assertSame([0, 1, 2, 3], array_map(static fn ($ref) => $ref->position, $view->references));
        self::assertSame(
            [EventReferenceRole::Root, EventReferenceRole::Root, EventReferenceRole::Parent, EventReferenceRole::Parent],
            array_map(static fn ($ref) => $ref->role, $view->references),
        );
        self::assertSame(
            ['WSS://Relay.One/Path', 'WsS://Relay.Two', 'WS://Relay.Three', 'wS://Relay.Four'],
            array_map(static fn ($ref) => $ref->relayHint, $view->references),
        );
        self::assertSame(EventReferenceStatus::Invalid, $view->references[3]->status);
        self::assertSame('malformed', $view->references[3]->rawValue);
    }

    public function testNip56ReportTypesAreNeverRelayHints(): void
    {
        $tags = [
            ['e', self::TARGET_ID, 'spam'],
            ['p', self::PROFILE, 'nudity'],
            ['e', 'malformed', 'malware'],
            ['p', null, 'illegal'],
            ['e', self::TARGET_ID, 'spam'],
            ['a', '30023:' . self::AUTHOR . ':article', 'wss://ordinary.example'],
        ];
        $view = $this->present(1984, $tags);

        self::assertSame($tags, $view->getRawTags());
        self::assertSame(range(0, 5), array_map(static fn ($ref) => $ref->position, $view->references));
        foreach (array_slice($view->references, 0, 5) as $reference) {
            self::assertNull($reference->relayHint);
            self::assertNull($reference->versionEventId);
        }
        self::assertSame(EventReferenceStatus::Invalid, $view->references[2]->status);
        self::assertSame(EventReferenceStatus::Invalid, $view->references[3]->status);
        self::assertSame(EventReferenceStatus::Unresolved, $view->references[0]->status);
        self::assertSame($view->references[0]->target, $view->references[4]->target);
        self::assertSame('wss://ordinary.example', $view->references[5]->relayHint);
    }

    /** @dataProvider reportClassifications */
    public function testNip56NonRelayStringsRemainReportMetadata(string $classification): void
    {
        $tags = [
            ['e', self::TARGET_ID, $classification],
            ['p', self::PROFILE, $classification],
            ['x', 'blob-hash', $classification],
        ];
        $view = $this->present(1984, $tags);

        self::assertSame($tags, $view->getRawTags());
        self::assertSame([0, 1], array_map(static fn ($ref) => $ref->position, $view->references));
        foreach ($view->references as $reference) {
            self::assertSame(EventReferenceStatus::Unresolved, $reference->status);
            self::assertNull($reference->relayHint);
        }
    }

    /** @return array<string, array{string}> */
    public static function reportClassifications(): array
    {
        return [
            'nudity' => ['nudity'],
            'malware' => ['malware'],
            'profanity' => ['profanity'],
            'illegal' => ['illegal'],
            'spam' => ['spam'],
            'impersonation' => ['impersonation'],
            'other' => ['other'],
            'unknown classification' => ['unknown'],
            'ordinary non-URL value' => ['relay.example'],
            'non-WebSocket URL' => ['https://relay.example'],
            'similar but unsupported scheme' => ['WSSX://relay.example'],
        ];
    }

    public function testNip56SupportingReferencesKeepWebSocketSchemeCandidates(): void
    {
        $tags = [
            ['p', self::PROFILE, 'impersonation'],
            ['e', self::TARGET_ID, 'wss://relay.example'],
            ['x', 'blob-hash', 'malware'],
            ['e', self::TARGET_ID, 'WSS://Relay.Example/Evidence'],
            ['p', self::AUTHOR, 'ws://profile.example'],
            ['p', 'not-a-pubkey', 'WS://invalid-profile.example'],
            ['e', 'not-an-event-id', 'WsS://invalid-event.example'],
            ['p', self::PROFILE, 'relay.example'],
            ['e', self::TARGET_ID, 'https://not-a-websocket.example'],
            ['e', self::TARGET_ID, 'wss://relay.example'],
            ['a', '30023:' . self::AUTHOR . ':article', 'wss://ordinary.example'],
        ];
        $view = $this->present(1984, $tags);

        self::assertSame($tags, $view->getRawTags());
        self::assertSame([0, 1, 3, 4, 5, 6, 7, 8, 9, 10], array_map(static fn ($ref) => $ref->position, $view->references));
        self::assertSame([
            null, 'wss://relay.example', 'WSS://Relay.Example/Evidence',
            'ws://profile.example', 'WS://invalid-profile.example', 'WsS://invalid-event.example',
            null, null, 'wss://relay.example', 'wss://ordinary.example',
        ], array_map(static fn ($ref) => $ref->relayHint, $view->references));
        self::assertSame(EventReferenceStatus::Unresolved, $view->references[1]->status);
        self::assertSame($view->references[1]->target, $view->references[8]->target);
        self::assertSame(EventReferenceStatus::Invalid, $view->references[4]->status);
        self::assertSame('not-a-pubkey', $view->references[4]->rawValue);
        self::assertNull($view->references[4]->target);
        self::assertSame(EventReferenceStatus::Invalid, $view->references[5]->status);
        self::assertSame('not-an-event-id', $view->references[5]->rawValue);
        self::assertNull($view->references[5]->target);
    }

    public function testVersionMetadataDoesNotDecideAutoUpdatePolicy(): void
    {
        $tags = [
            ['auto-update', 'ask'],
            ['a', '30023:' . self::AUTHOR . ':article', '', self::VERSION_ID],
        ];
        $view = $this->present(30040, $tags);

        self::assertSame('ask', $view->autoUpdate);
        self::assertSame(self::VERSION_ID, $view->references[0]->versionEventId);
        self::assertNull($this->present(30045, $tags)->autoUpdate);
        self::assertNull($this->present(30040, [['auto-update', 'unknown']])->autoUpdate);
    }

    public function testFollowRoleIsNotAssignedToOtherKindsProfileTags(): void
    {
        $tags = [['p', self::PROFILE, 'wss://profiles.example', 'petname']];
        $follow = $this->present(3, $tags)->references[0];
        $mention = $this->present(30023, $tags)->references[0];

        self::assertSame(EventReferenceType::Profile, $follow->type);
        self::assertSame(EventReferenceRole::Follow, $follow->role);
        self::assertSame('wss://profiles.example', $follow->relayHint);
        self::assertSame(EventReferenceRole::Reference, $mention->role);
        self::assertNull($mention->versionEventId);
    }

    public function testFollowPackMembersKeepTheirOriginalOrderAndDuplicates(): void
    {
        $tags = [
            ['d', 'recommended-people'],
            ['p', self::PROFILE, 'wss://one.example'],
            ['p', 'malformed'],
            ['t', 'nostr'],
            ['p', self::PROFILE, 'wss://two.example'],
            ['p', self::AUTHOR],
            ['P', self::PROFILE],
        ];
        $view = $this->present(39089, $tags, 'opaque encrypted list content');

        self::assertSame($tags, $view->getRawTags());
        self::assertSame([1, 2, 4, 5], array_map(static fn ($ref) => $ref->position, $view->references));
        self::assertSame(
            [EventReferenceRole::FollowPackMember, EventReferenceRole::FollowPackMember,
                EventReferenceRole::FollowPackMember, EventReferenceRole::FollowPackMember],
            array_map(static fn ($ref) => $ref->role, $view->references),
        );
        self::assertSame(self::PROFILE, $view->references[0]->target);
        self::assertSame($view->references[0]->target, $view->references[2]->target);
        self::assertSame('wss://one.example', $view->references[0]->relayHint);
        self::assertSame('wss://two.example', $view->references[2]->relayHint);
        self::assertSame(EventReferenceStatus::Invalid, $view->references[1]->status);
        self::assertNull($view->textContent);
        self::assertNull($view->description);
    }

    public function testCommentRootAndParentCaseRemainDistinct(): void
    {
        $coordinate = '30023:' . self::AUTHOR . ':article';
        $view = $this->present(1111, [
            ['A', $coordinate],
            ['E', self::TARGET_ID, 'wss://relay.example', self::AUTHOR],
            ['P', self::AUTHOR],
            ['a', $coordinate],
            ['e', self::TARGET_ID, 'wss://relay.example', self::AUTHOR],
            ['p', self::PROFILE], // May also be a mention.
        ]);

        self::assertSame(['A', 'E', 'P', 'a', 'e', 'p'], array_map(static fn ($ref) => $ref->tagName, $view->references));
        self::assertSame(
            [EventReferenceRole::Root, EventReferenceRole::Root, EventReferenceRole::Root,
                EventReferenceRole::Parent, EventReferenceRole::Parent, EventReferenceRole::Reference],
            array_map(static fn ($ref) => $ref->role, $view->references),
        );
        self::assertNull($view->references[1]->versionEventId);
        self::assertNull($view->references[4]->versionEventId);

        // Unknown kinds do not acquire uppercase-reference semantics by guesswork.
        $unknown = $this->present(60000, [['A', $coordinate], ['P', self::AUTHOR]]);
        self::assertSame([], $unknown->references);
        self::assertCount(2, $unknown->getRawTags());
    }

    public function testRawTagSnapshotCannotBeMutatedThroughMalformedObjects(): void
    {
        $object = (object) ['nested' => (object) ['value' => 'original']];
        $view = $this->present(60000, [$object, ['a', 'bad']]);
        $object->nested->value = 'changed in source';

        $returnedTags = $view->getRawTags();
        self::assertSame('original', $returnedTags[0]->nested->value);
        $returnedTags[0]->nested->value = 'changed in copy';
        $returnedTags[1][1] = 'also changed';
        self::assertSame('original', $view->getRawTags()[0]->nested->value);
        self::assertSame('bad', $view->getRawTags()[1][1]);
        self::assertTrue((new \ReflectionClass($view))->isReadOnly());
        self::assertTrue((new \ReflectionClass($view->references[0]))->isReadOnly());
    }

    /** @param array<array-key, mixed> $tags */
    private function present(int $kind, array $tags = [], string $content = '', string $pubkey = self::AUTHOR): EventPresentation
    {
        return $this->factory->fromAuthorizedFields(self::SOURCE_ID, $kind, $pubkey, 1710000000, $tags, $content);
    }

    /** @param array<array-key, mixed> $tags */
    private function directPresentation(
        array $tags = [],
        bool $hasContentWarning = false,
        ?string $contentWarningReason = null,
        bool $isSensitive = false,
        ?string $description = 'Hidden description',
        ?string $descriptionOnReveal = null,
    ): EventPresentation {
        return new EventPresentation(
            eventId: self::SOURCE_ID,
            kind: 1,
            authorPubkey: self::AUTHOR,
            authorNpub: null,
            coordinate: null,
            recordUid: null,
            createdAt: 1710000000,
            publishedAt: null,
            title: 'Title',
            description: $description,
            descriptionOnReveal: $descriptionOnReveal,
            imageUrl: null,
            hasContentWarning: $hasContentWarning,
            contentWarningReason: $contentWarningReason,
            isSensitive: $isSensitive,
            bodyFormat: EventBodyFormat::PlainText,
            textContent: 'Original body',
            autoUpdate: null,
            references: [],
            rawTags: $tags,
        );
    }

    /** @param array<array-key, mixed> $tags */
    private function event(int $kind, array $tags, string $content, string $pubkey = self::AUTHOR): Event
    {
        $event = new Event();
        $event->setId(self::SOURCE_ID);
        $event->setKind($kind);
        $event->setPubkey($pubkey);
        $event->setCreatedAt(1710000000);
        $event->setTags($tags);
        $event->setContent($content);

        return $event;
    }
}

final class ThrowingNpubEncoder
{
    public static function npub(string $pubkey): never
    {
        throw new \RuntimeException('Unexpected npub encoding failure');
    }
}
