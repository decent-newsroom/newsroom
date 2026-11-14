<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Graph;

use App\Service\Graph\RecordIdentityService;
use App\Service\Graph\ReferenceParserService;
use PHPUnit\Framework\TestCase;

class ReferenceParserServiceTest extends TestCase
{
    private ReferenceParserService $parser;

    protected function setUp(): void
    {
        $this->parser = new ReferenceParserService(new RecordIdentityService());
    }

    public function testParseSingleATag(): void
    {
        $pk = str_repeat('ab', 32);
        $tags = [['d', 'my-mag'], ['a', "30023:{$pk}:my-article"]];
        $refs = $this->parser->parseFromTagsArray('ev1', 30040, $tags);

        $this->assertCount(1, $refs);
        $this->assertSame('ev1', $refs[0]->sourceEventId);
        $this->assertSame('a', $refs[0]->tagName);
        $this->assertSame('coordinate', $refs[0]->targetRefType);
        $this->assertSame(30023, $refs[0]->targetKind);
        $this->assertSame('contains', $refs[0]->relation);
        $this->assertTrue($refs[0]->isStructural);
        $this->assertTrue($refs[0]->isResolvable);
        $this->assertSame(1, $refs[0]->position);
    }

    public function testParseMultipleATagsPreservesOrder(): void
    {
        $pk = str_repeat('ab', 32);
        $tags = [
            ['a', "30040:{$pk}:cat1"],
            ['a', "30023:{$pk}:art1"],
            ['a', "30041:{$pk}:ch1"],
        ];
        $refs = $this->parser->parseFromTagsArray('ev1', 30040, $tags);
        $this->assertCount(3, $refs);
        $this->assertSame(0, $refs[0]->position);
        $this->assertSame(1, $refs[1]->position);
        $this->assertSame(2, $refs[2]->position);
    }

    public function testMixedPublicationContentIsResolvableContainment(): void
    {
        $pubkey = str_repeat('ab', 32);
        $tags = [];
        foreach ([30023, 30041, 30818, 30817] as $kind) {
            $tags[] = ['a', "{$kind}:{$pubkey}:Topic", 'wss://relay.example', 'content'];
        }
        $references = $this->parser->parseFromTagsArray('category', 30040, $tags);

        $this->assertCount(4, $references);
        foreach ($references as $position => $reference) {
            $this->assertTrue($reference->isStructural);
            $this->assertTrue($reference->isResolvable);
            $this->assertSame('contains', $reference->relation);
            $this->assertSame($position, $reference->position);
            $this->assertSame('Topic', $reference->targetDTag);
            $this->assertSame('content', $reference->marker);
        }
    }

    public function testWikiReferencesAreNotContainmentOutsideAnIndex(): void
    {
        $pubkey = str_repeat('ab', 32);
        $references = $this->parser->parseFromTagsArray('wiki', 30818, [['a', "30818:{$pubkey}:source"]]);

        $this->assertFalse($references[0]->isStructural);
        $this->assertTrue($references[0]->isResolvable);
        $this->assertSame('references', $references[0]->relation);
    }

    public function testNonStructuralForArticleSource(): void
    {
        $pk = str_repeat('ab', 32);
        $refs = $this->parser->parseFromTagsArray('ev1', 30023, [['a', "30040:{$pk}:mag"]]);
        $this->assertFalse($refs[0]->isStructural);
        $this->assertSame('references', $refs[0]->relation);
    }

    public function testSkipsNonATags(): void
    {
        $tags = [['d', 'slug'], ['t', 'topic'], ['P', str_repeat('ab', 32)]];
        $this->assertCount(0, $this->parser->parseFromTagsArray('ev1', 30040, $tags));
    }

    public function testSkipsMalformedATags(): void
    {
        $tags = [['a', 'invalid'], ['a', ''], ['a']];
        $this->assertCount(0, $this->parser->parseFromTagsArray('ev1', 30040, $tags));
    }

    public function testMarkerExtractedFromIndex3(): void
    {
        $pk = str_repeat('ab', 32);
        $tags = [['a', "30023:{$pk}:art", 'wss://relay.example.com', 'root']];
        $refs = $this->parser->parseFromTagsArray('ev1', 30040, $tags);
        $this->assertSame('root', $refs[0]->marker);
    }

    public function testCurationSetIsStructural(): void
    {
        $pk = str_repeat('ab', 32);
        $refs = $this->parser->parseFromTagsArray('ev1', 30004, [['a', "30023:{$pk}:art"]]);
        $this->assertTrue($refs[0]->isStructural);
        $this->assertSame('contains', $refs[0]->relation);
    }

    public function testNonResolvableTargetKind(): void
    {
        $pk = str_repeat('ab', 32);
        $refs = $this->parser->parseFromTagsArray('ev1', 30040, [['a', "31337:{$pk}:custom"]]);
        $this->assertFalse($refs[0]->isResolvable);
    }

    public function testCanonicalCoordNormalizesPubkey(): void
    {
        $refs = $this->parser->parseFromTagsArray('ev1', 30040, [['a', '30023:AABB:art']]);
        $this->assertSame('30023:aabb:art', $refs[0]->targetCoord);
        $this->assertSame('aabb', $refs[0]->targetPubkey);
    }

    public function testEmptyTagsReturnsEmpty(): void
    {
        $this->assertCount(0, $this->parser->parseFromTagsArray('ev1', 30040, []));
    }

    public function testNoMarkerWhenOnlyRelayHint(): void
    {
        $pk = str_repeat('ab', 32);
        $tags = [['a', "30023:{$pk}:art", 'wss://relay.example.com']];
        $refs = $this->parser->parseFromTagsArray('ev1', 30040, $tags);
        $this->assertNull($refs[0]->marker);
    }

    public function testLowercaseEReferencesPreserveOrderedDuplicateExactIdsAndSourcePositions(): void
    {
        $targetId = str_repeat('ab', 32);
        $otherTargetId = str_repeat('cd', 32);
        $tags = [
            ['d', 'source'],
            ['e', $targetId],
            ['t', 'topic'],
            ['e', $targetId],
            ['e', $otherTargetId],
            ['E', str_repeat('ef', 32)],
        ];

        $references = $this->parser->parseFromTagsArray('source-event', 1, $tags);

        $this->assertCount(3, $references);
        $this->assertSame([1, 3, 4], array_map(static fn ($reference) => $reference->position, $references));
        $this->assertSame([$targetId, $targetId, $otherTargetId], array_map(
            static fn ($reference) => $reference->targetEventId,
            $references,
        ));
        foreach ($references as $reference) {
            $this->assertSame('source-event', $reference->sourceEventId);
            $this->assertSame('e', $reference->tagName);
            $this->assertSame('event_id', $reference->targetRefType);
            $this->assertNull($reference->targetCoord);
            $this->assertSame('references', $reference->relation);
            $this->assertFalse($reference->isStructural);
            $this->assertTrue($reference->isResolvable);
        }
    }

    public function testExactEReferenceRetainsNip10MarkerAndRelayHintWithoutBecomingStructural(): void
    {
        $targetId = str_repeat('ab', 32);
        $references = $this->parser->parseFromTagsArray(
            'reply-event',
            1,
            [['e', $targetId, 'wss://relay.example', 'reply']],
        );

        $this->assertCount(1, $references);
        $this->assertSame('reply', $references[0]->marker);
        $this->assertSame('wss://relay.example', $references[0]->relayHint);
        $this->assertSame('references', $references[0]->relation);
        $this->assertFalse($references[0]->isStructural);
    }

    public function testExactEventIdDoesNotBecomeCoordinateOrLatestAddressReference(): void
    {
        $targetId = str_repeat('ab', 32);
        $pubkey = str_repeat('cd', 32);
        $references = $this->parser->parseFromTagsArray('source', 30040, [
            ['e', $targetId],
            ['a', "30023:{$pubkey}:article"],
        ]);

        $this->assertCount(2, $references);
        $this->assertSame($targetId, $references[0]->targetEventId);
        $this->assertNull($references[0]->targetCoord);
        $this->assertNull($references[0]->targetKind);
        $this->assertSame('event_id', $references[0]->targetRefType);
        $this->assertNull($references[1]->targetEventId);
        $this->assertSame("30023:{$pubkey}:article", $references[1]->targetCoord);
        $this->assertSame('coordinate', $references[1]->targetRefType);
        $this->assertTrue($references[1]->isStructural);
    }

    public function testMalformedAndUppercaseEventReferencesAreNotParsedAsLowercaseE(): void
    {
        $references = $this->parser->parseFromTagsArray('source', 1, [
            ['e', 'not-an-event-id'],
            ['E', str_repeat('ab', 32)],
        ]);

        $this->assertSame([], $references);
    }

    public function testProfileReferencesAreKindAwareAndNeverStructural(): void
    {
        $pubkey = str_repeat('AB', 32);
        foreach ([3 => 'follows', 39089 => 'member', 1 => 'references', 30040 => 'references'] as $kind => $relation) {
            $references = $this->parser->parseFromTagsArray('source', $kind, [
                ['p', $pubkey, 'WSS://relay.example', 'explicit-petname'],
            ]);

            $this->assertCount(1, $references);
            $ref = $references[0];
            $this->assertSame('source', $ref->sourceEventId);
            $this->assertSame('p', $ref->tagName);
            $this->assertSame('profile', $ref->targetRefType);
            $this->assertSame(strtolower($pubkey), $ref->targetPubkey);
            $this->assertSame($relation, $ref->relation);
            $this->assertSame('WSS://relay.example', $ref->relayHint);
            $this->assertSame(['WSS://relay.example', 'explicit-petname'], json_decode($ref->auxiliaryData, true));
            $this->assertNull($ref->marker);
            $this->assertNull($ref->targetEventId);
            $this->assertNull($ref->targetCoord);
            $this->assertFalse($ref->isStructural);
            $this->assertFalse($ref->isResolvable);
        }
    }

    public function testProfileOccurrencesRetainPositionsDuplicatesAndMixedReferenceTypes(): void
    {
        $pubkey = str_repeat('ab', 32);
        $eventId = str_repeat('cd', 32);
        $refs = $this->parser->parseFromTagsArray('source', 30040, [
            ['d', 'index'],
            ['p', $pubkey, '', 'name'],
            ['e', $eventId],
            ['p', $pubkey, 'nudity'],
            ['a', "30023:{$pubkey}:article"],
            ['p', 'invalid'],
            ['p', str_repeat('g', 64)],
            ['p', str_repeat('a', 63)],
            ['P', $pubkey],
            ['p', 123],
        ]);

        $this->assertSame([1, 2, 3, 4], array_map(static fn ($ref) => $ref->position, $refs));
        $this->assertSame(['p', 'e', 'p', 'a'], array_map(static fn ($ref) => $ref->tagName, $refs));
        $this->assertSame($pubkey, $refs[0]->targetPubkey);
        $this->assertSame($pubkey, $refs[2]->targetPubkey);
        $this->assertNull($refs[2]->relayHint);
        $this->assertSame(['nudity'], json_decode($refs[2]->auxiliaryData, true));
        $this->assertSame($eventId, $refs[1]->targetEventId);
        $this->assertFalse($refs[1]->isStructural);
        $this->assertSame("30023:{$pubkey}:article", $refs[3]->targetCoord);
        $this->assertTrue($refs[3]->isStructural);
    }

    public function testProfileAuxiliaryIndexIsBoundedAndDoesNotInferRelayFromOtherSlots(): void
    {
        $pubkey = str_repeat('ab', 32);
        $refs = $this->parser->parseFromTagsArray('source', 1984, [
            ['p', $pubkey, 'impersonation', 'wss://not-slot-two.example'],
            ['p', $pubkey, 'wss://' . str_repeat('x', 5000)],
        ]);

        $this->assertNull($refs[0]->relayHint);
        $this->assertSame(['impersonation', 'wss://not-slot-two.example'], json_decode($refs[0]->auxiliaryData, true));
        $this->assertNull($refs[1]->relayHint);
        $this->assertNull($refs[1]->auxiliaryData);
    }
}
