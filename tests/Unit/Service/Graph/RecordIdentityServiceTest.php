<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Graph;

use App\Service\Graph\RecordIdentityService;
use PHPUnit\Framework\TestCase;

class RecordIdentityServiceTest extends TestCase
{
    private RecordIdentityService $service;

    protected function setUp(): void
    {
        $this->service = new RecordIdentityService();
    }

    // ── isParameterizedReplaceable ──────────────────────────────────────

    public function testIsParameterizedReplaceableTrueForKind30000(): void
    {
        $this->assertTrue($this->service->isParameterizedReplaceable(30000));
    }

    public function testIsParameterizedReplaceableTrueForKind39999(): void
    {
        $this->assertTrue($this->service->isParameterizedReplaceable(39999));
    }

    public function testIsParameterizedReplaceableTrueForKind30023(): void
    {
        $this->assertTrue($this->service->isParameterizedReplaceable(30023));
    }

    public function testIsParameterizedReplaceableFalseForKind1(): void
    {
        $this->assertFalse($this->service->isParameterizedReplaceable(1));
    }

    public function testIsParameterizedReplaceableFalseForKind10002(): void
    {
        $this->assertFalse($this->service->isParameterizedReplaceable(10002));
    }

    // ── isReplaceable ──────────────────────────────────────────────────

    public function testIsReplaceableTrueForKind0(): void
    {
        $this->assertTrue($this->service->isReplaceable(0));
    }

    public function testIsReplaceableTrueForKind3(): void
    {
        $this->assertTrue($this->service->isReplaceable(3));
    }

    public function testIsReplaceableTrueForKind10002(): void
    {
        $this->assertTrue($this->service->isReplaceable(10002));
    }

    public function testIsReplaceableFalseForKind1(): void
    {
        $this->assertFalse($this->service->isReplaceable(1));
    }

    public function testIsReplaceableFalseForKind30023(): void
    {
        $this->assertFalse($this->service->isReplaceable(30023));
    }

    // ── deriveCoordinate ───────────────────────────────────────────────

    public function testDeriveCoordinateForParameterizedReplaceable(): void
    {
        $result = $this->service->deriveCoordinate(30040, 'AbCdEf1234', 'my-slug');
        $this->assertSame('30040:abcdef1234:my-slug', $result);
    }

    public function testDeriveCoordinateNormalizesEmptyDTag(): void
    {
        $result = $this->service->deriveCoordinate(30040, 'abcdef', '');
        $this->assertSame('30040:abcdef:', $result);
    }

    public function testDeriveCoordinateNormalizesNullDTag(): void
    {
        $result = $this->service->deriveCoordinate(30040, 'abcdef', null);
        $this->assertSame('30040:abcdef:', $result);
    }

    public function testDeriveCoordinateReturnsNullForNonParameterizedReplaceable(): void
    {
        $this->assertNull($this->service->deriveCoordinate(1, 'abcdef', 'test'));
    }

    public function testDeriveCoordinateLowercasesPubkey(): void
    {
        $result = $this->service->deriveCoordinate(30023, 'AABBCCDD', 'article-slug');
        $this->assertSame('30023:aabbccdd:article-slug', $result);
    }

    // ── deriveReplaceableCoordinate ────────────────────────────────────

    public function testDeriveReplaceableCoordinateForKind0(): void
    {
        $result = $this->service->deriveReplaceableCoordinate(0, 'AbCd');
        $this->assertSame('0:abcd', $result);
    }

    public function testDeriveReplaceableCoordinateReturnsNullForNonReplaceable(): void
    {
        $this->assertNull($this->service->deriveReplaceableCoordinate(1, 'abcd'));
    }

    // ── deriveRecordUid ────────────────────────────────────────────────

    public function testDeriveRecordUidForParameterizedReplaceable(): void
    {
        $result = $this->service->deriveRecordUid(30040, 'abcdef', 'my-mag');
        $this->assertSame('coord:30040:abcdef:my-mag', $result);
    }

    public function testDeriveRecordUidForReplaceable(): void
    {
        $result = $this->service->deriveRecordUid(0, 'abcdef');
        $this->assertSame('coord:0:abcdef', $result);
    }

    public function testDeriveRecordUidForImmutableEvent(): void
    {
        $result = $this->service->deriveRecordUid(1, 'abcdef', null, 'event123');
        $this->assertSame('event:event123', $result);
    }

    public function testDeriveRecordUidThrowsForImmutableWithoutEventId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->deriveRecordUid(1, 'abcdef');
    }

    // ── deriveRefType ──────────────────────────────────────────────────

    public function testDeriveRefTypeCoordinateForParameterizedReplaceable(): void
    {
        $this->assertSame('coordinate', $this->service->deriveRefType(30040));
    }

    public function testDeriveRefTypeCoordinateForReplaceable(): void
    {
        $this->assertSame('coordinate', $this->service->deriveRefType(0));
    }

    public function testDeriveRefTypeEventForImmutable(): void
    {
        $this->assertSame('event', $this->service->deriveRefType(1));
    }

    // ── deriveEntityType ───────────────────────────────────────────────

    public function testDeriveEntityTypeMagazine(): void
    {
        $this->assertSame('magazine', $this->service->deriveEntityType(30040));
    }

    public function testDeriveEntityTypeChapter(): void
    {
        $this->assertSame('chapter', $this->service->deriveEntityType(30041));
    }

    public function testDeriveEntityTypeArticle(): void
    {
        $this->assertSame('article', $this->service->deriveEntityType(30023));
    }

    public function testDeriveEntityTypeDraft(): void
    {
        $this->assertSame('draft', $this->service->deriveEntityType(30024));
    }

    public function testDeriveEntityTypeUnknown(): void
    {
        $this->assertSame('unknown', $this->service->deriveEntityType(99999));
    }

    // ── decomposeATag ──────────────────────────────────────────────────

    public function testDecomposeATagValid(): void
    {
        $result = $this->service->decomposeATag('30040:abcdef1234:my-slug');
        $this->assertSame(['kind' => 30040, 'pubkey' => 'abcdef1234', 'd_tag' => 'my-slug'], $result);
    }

    public function testDecomposeATagLowercasesPubkey(): void
    {
        $result = $this->service->decomposeATag('30023:AABBCCDD:article');
        $this->assertNotNull($result);
        $this->assertSame('aabbccdd', $result['pubkey']);
    }

    public function testDecomposeATagEmptyDTag(): void
    {
        $result = $this->service->decomposeATag('30040:abcdef:');
        $this->assertNotNull($result);
        $this->assertSame('', $result['d_tag']);
    }

    public function testDecomposeATagMissingDTag(): void
    {
        // Only two parts — d_tag defaults to empty
        $result = $this->service->decomposeATag('30040:abcdef');
        $this->assertNotNull($result);
        $this->assertSame('', $result['d_tag']);
    }

    public function testDecomposeATagInvalidNoColon(): void
    {
        $this->assertNull($this->service->decomposeATag('invalid'));
    }

    public function testDecomposeATagInvalidZeroKind(): void
    {
        $this->assertNull($this->service->decomposeATag('0:abcdef:slug'));
    }

    public function testDecomposeATagInvalidEmptyPubkey(): void
    {
        $this->assertNull($this->service->decomposeATag('30040::slug'));
    }

    // ── canonicalizeATag ───────────────────────────────────────────────

    public function testCanonicalizeATagNormalized(): void
    {
        $result = $this->service->canonicalizeATag('30040:AABB:my-slug');
        $this->assertSame('30040:aabb:my-slug', $result);
    }

    public function testCanonicalizeATagReturnsNullForInvalid(): void
    {
        $this->assertNull($this->service->canonicalizeATag('invalid'));
    }

    public function testCanonicalizeATagPreservesDTag(): void
    {
        $result = $this->service->canonicalizeATag('30023:abcd:My-Article-Slug');
        $this->assertSame('30023:abcd:My-Article-Slug', $result);
    }
}

