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
        $this->assertSame(0, $refs[0]->position);
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
        $tags = [['d', 'slug'], ['t', 'topic'], ['p', str_repeat('ab', 32)]];
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
}
