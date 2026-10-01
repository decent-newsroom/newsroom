<?php

declare(strict_types=1);

namespace App\Tests\Unit\Unfold;

use App\Unfold\PublicationDraftStore;
use DecentNewsroom\UnfoldBundle\Config\PublicationDraft;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class PublicationDraftStoreTest extends TestCase
{
    private const OWNER = 'a234567890123456789012345678901234567890123456789012345678901234';

    public function testSaveWritesScalarJsonAndRefreshesConfiguredTtl(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('set')->with(
            'unfold:draft:' . self::OWNER . ':magazine',
            self::callback(static function (string $json): bool {
                self::assertSame(
                    [
                        'owner_pubkey' => self::OWNER,
                        'dtag' => 'magazine',
                        'title' => 'Title',
                        'summary' => 'Summary',
                        'image_url' => null,
                        'language' => null,
                        'tags' => ['news'],
                        'theme' => 'default',
                        'current_step' => 1,
                    ],
                    json_decode($json, true, 512, JSON_THROW_ON_ERROR),
                );

                return true;
            }),
            ['ex' => 3600],
        )->willReturn(true);

        $this->store($redis)->save(PublicationDraft::create(self::OWNER, 'magazine', 'Title', 'Summary', tags: ['news']));
    }

    public function testFindNormalizesRequestedProvisionalKeyAndReconstitutesDraft(): void
    {
        $payload = json_encode(
            PublicationDraft::create(self::OWNER, 'Magazine', 'Title')->toArray(),
            JSON_THROW_ON_ERROR,
        );
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('get')
            ->with('unfold:draft:' . self::OWNER . ':Magazine')
            ->willReturn($payload);

        $draft = $this->store($redis)->findByProvisionalKey(strtoupper(self::OWNER) . ':Magazine');

        self::assertSame(self::OWNER, $draft?->ownerPubkey);
        self::assertSame('Magazine', $draft?->dtag);
        self::assertSame('Title', $draft?->title);
    }

    public function testCorruptPayloadIsDeletedAndLogged(): void
    {
        $key = 'unfold:draft:' . self::OWNER . ':magazine';
        $redis = $this->createMock(\Redis::class);
        $redis->method('get')->with($key)->willReturn('{not-json');
        $redis->expects(self::once())->method('del')->with($key)->willReturn(1);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Discarded corrupt publication draft.',
            self::callback(static fn (array $context): bool => $context['key'] === $key && $context['error'] !== ''),
        );

        self::assertNull($this->store($redis, $logger)->findByProvisionalKey(self::OWNER . ':magazine'));
    }

    public function testStoredDraftWithDifferentIdentityIsDiscarded(): void
    {
        $key = 'unfold:draft:' . self::OWNER . ':magazine';
        $payload = json_encode(
            PublicationDraft::create(str_repeat('b', 64), 'magazine')->toArray(),
            JSON_THROW_ON_ERROR,
        );
        $redis = $this->createMock(\Redis::class);
        $redis->method('get')->with($key)->willReturn($payload);
        $redis->expects(self::once())->method('del')->with($key)->willReturn(1);

        self::assertNull($this->store($redis)->findByProvisionalKey(self::OWNER . ':magazine'));
    }

    public function testInvalidProvisionalKeyDoesNotReachRedis(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::never())->method('get');
        $this->expectException(\InvalidArgumentException::class);

        $this->store($redis)->findByProvisionalKey('not-a-draft-key');
    }

    public function testDiscardThrowsWhenRedisReportsFailure(): void
    {
        $key = 'unfold:draft:' . self::OWNER . ':magazine';
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('del')->with($key)->willReturn(false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $this->expectException(\RuntimeException::class);

        $this->store($redis, $logger)->discardByProvisionalKey(self::OWNER . ':magazine');
    }

    public function testMigrationUsesAtomicNonOverwritingRename(): void
    {
        $draft = PublicationDraft::create(self::OWNER, 'magazine');
        $provisional = 'unfold:draft:' . $draft->provisionalKey();
        $canonical = 'unfold:draft:' . $draft->canonicalKey();
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())->method('eval')->with(
            self::callback(static function (string $script): bool {
                self::assertStringContainsString("redis.call('RENAMENX', KEYS[1], KEYS[2])", $script);
                self::assertStringContainsString("redis.call('EXISTS', KEYS[2])", $script);

                return true;
            }),
            [$provisional, $canonical],
            2,
        )->willReturn(1);

        $this->store($redis)->migrateProvisionalToCanonical($draft);
    }

    public function testMigrationRejectsExistingCanonicalDraft(): void
    {
        $draft = PublicationDraft::create(self::OWNER, 'magazine');
        $redis = $this->createMock(\Redis::class);
        $redis->method('eval')->willReturn(0);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $this->expectException(\RuntimeException::class);

        $this->store($redis, $logger)->migrateProvisionalToCanonical($draft);
    }

    private function store(\Redis $redis, ?LoggerInterface $logger = null): PublicationDraftStore
    {
        return new PublicationDraftStore($redis, $logger ?? $this->createMock(LoggerInterface::class), 3600);
    }
}
